<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayRefundRequestResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\SalesLabels;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\GatewayRefundService;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Domain\Models\GatewayRefundRequest;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Daftar tugas finance: pengembalian dana yang harus dikerjakan di dashboard acquirer.
 *
 * Halaman ini adalah satu-satunya tempat sebuah retur gateway bisa menjadi retur sungguhan, dan
 * ambangnya sengaja lebih tinggi daripada melihat transaksi: menyatakan dana sudah dikembalikan
 * sama nilainya dengan memindahkan uang. Karena itu aksinya menuntut `company.manage` — izin yang
 * sama dengan mengganti rekening penerima dana di layar Payment Gateway — sementara daftarnya
 * boleh dilihat siapa pun yang boleh melihat transaksi, supaya manajer outlet tahu apa yang
 * sedang ditunggu pelanggannya.
 */
class GatewayRefundRequestResource extends Resource
{
    protected static ?string $model = GatewayRefundRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $modelLabel = 'pengajuan pengembalian dana';

    protected static ?string $pluralModelLabel = 'Pengembalian Dana Gateway';

    protected static ?string $slug = 'pengembalian-dana';

    protected static ?int $navigationSort = 3;

    public const STATUS_LABEL = [
        GatewayRefundRequest::PENDING => 'Menunggu diproses',
        GatewayRefundRequest::SETTLED => 'Sudah dikembalikan',
        GatewayRefundRequest::CANCELLED => 'Dibatalkan',
    ];

    public static function canViewAny(): bool
    {
        return SalesLabels::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Siapa yang boleh menyatakan dananya sudah kembali.
     *
     * Dua izin, bukan satu: `accounting.manage` karena peran **finance**-lah yang benar-benar
     * membuka dashboard acquirer dan memindahkan uangnya, dan `company.manage` supaya pemilik serta
     * admin company tidak terkunci di luar tugas yang jadi tanggung jawab mereka. Yang sengaja
     * TIDAK masuk: kasir dan manajer outlet — mereka boleh mengajukan lewat POS, tetapi menyatakan
     * uang sudah berpindah adalah pekerjaan orang yang memegang dashboard-nya.
     */
    public static function canResolve(): bool
    {
        $user = SalesLabels::user();

        return $user !== null && ($user->can('accounting.manage') || $user->can('company.manage'));
    }

    /** @return Builder<GatewayRefundRequest> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('outlet_id', SalesLabels::outletIds());
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->where('status', GatewayRefundRequest::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order:id,business_date,receipt_no', 'outlet:id,name,code', 'requester:id,name', 'resolver:id,name']))
            ->columns([
                TextColumn::make('device_created_at')->label('Diajukan')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('order.receipt_no')->label('No. struk')->fontFamily('mono')->searchable(),
                TextColumn::make('outlet.name')->label('Outlet'),
                TextColumn::make('method')->label('Metode')->formatStateUsing(fn (string $state) => SalesLabels::method($state)),
                TextColumn::make('amount')->label('Nominal')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('requester.name')->label('Diajukan oleh')->placeholder('-'),
                TextColumn::make('reason')->label('Alasan')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        GatewayRefundRequest::PENDING => 'warning',
                        GatewayRefundRequest::SETTLED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('gateway_reference')->label('Ref. gateway')->fontFamily('mono')->placeholder('-')->toggleable(),
                TextColumn::make('resolver.name')->label('Diselesaikan oleh')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolved_at')->label('Waktu selesai')->dateTime('d M Y H.i', $tz)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('device_created_at', 'asc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(self::STATUS_LABEL)->default(GatewayRefundRequest::PENDING),
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => SalesLabels::outletOptions()),
            ])
            ->actions([
                Action::make('settle')
                    ->label('Sudah dikembalikan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (GatewayRefundRequest $record) => $record->status === GatewayRefundRequest::PENDING && self::canResolve())
                    ->modalHeading('Catat pengembalian dana')
                    ->modalDescription('Isi ini hanya setelah dananya benar-benar dikembalikan di dashboard gateway. Retur akan tercatat dan stok bergerak begitu disimpan.')
                    ->modalSubmitActionLabel('Catat retur')
                    ->form([
                        TextInput::make('gateway_reference')->label('Nomor referensi dari gateway')->required()->maxLength(64)
                            ->helperText('Bukti bahwa dananya benar-benar dikirim. Salin dari dashboard gateway.'),
                        Textarea::make('note')->label('Catatan (opsional)')->maxLength(300)->rows(2),
                    ])
                    ->action(function (GatewayRefundRequest $record, array $data): void {
                        self::run(fn (User $by) => app(GatewayRefundService::class)
                            ->settle($record, $by, (string) $data['gateway_reference'], $data['note'] ?? null),
                            'Retur tercatat. Stok dan laporan sudah menyesuaikan.');
                    }),
                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (GatewayRefundRequest $record) => $record->status === GatewayRefundRequest::PENDING && self::canResolve())
                    ->modalHeading('Batalkan pengajuan')
                    ->modalDescription('Dana tidak jadi dikembalikan. Tidak ada retur yang tercatat.')
                    ->form([
                        Textarea::make('reason')->label('Alasan pembatalan')->required()->minLength(3)->maxLength(300)->rows(2),
                    ])
                    ->action(function (GatewayRefundRequest $record, array $data): void {
                        self::run(fn (User $by) => app(GatewayRefundService::class)->cancel($record, $by, (string) $data['reason']),
                            'Pengajuan dibatalkan.');
                    }),
            ])
            ->emptyStateHeading('Tidak ada pengembalian dana yang menunggu')
            ->emptyStateDescription('Pengajuan muncul di sini saat kasir meminta pengembalian dana transaksi QRIS atau e-wallet.');
    }

    /**
     * Galat domain ditampilkan apa adanya sebagai notifikasi, bukan dilempar jadi layar 500:
     * dua orang yang membuka daftar ini bersamaan adalah kejadian biasa, bukan kesalahan sistem.
     *
     * @param  callable(User): mixed  $do
     */
    private static function run(callable $do, string $sukses): void
    {
        $user = SalesLabels::user();
        if ($user === null || ! self::canResolve()) {
            Notification::make()->danger()->title('Tidak berwenang')->body('Anda tidak berhak menyelesaikan pengajuan ini.')->send();

            return;
        }
        try {
            $do($user);
        } catch (SalesException $e) {
            Notification::make()->danger()->title('Tidak bisa diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayRefundRequests::route('/'),
        ];
    }
}
