<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\SalesLabels;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Daftar & rincian transaksi POS (FR-POS-04, FR-RPT dasar). Hanya baca: koreksi lewat void/refund di POS. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $modelLabel = 'transaksi';

    protected static ?string $pluralModelLabel = 'Transaksi';

    protected static ?string $slug = 'transaksi';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return SalesLabels::canView();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Order && in_array($record->outlet_id, SalesLabels::outletIds(), true);
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

    /** @return Builder<Order> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('outlet_id', SalesLabels::outletIds());
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['outlet:id,name,code', 'cashier:id,name']))
            ->columns([
                TextColumn::make('device_created_at')->label('Waktu')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('receipt_no')->label('No. struk')->searchable()->fontFamily('mono'),
                TextColumn::make('outlet.name')->label('Outlet'),
                TextColumn::make('cashier.name')->label('Kasir')->placeholder('-'),
                TextColumn::make('channel_code')->label('Channel')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (string $state) => SalesLabels::channel($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => SalesLabels::status($state))
                    ->color(fn (string $state) => SalesLabels::statusColor($state)),
                TextColumn::make('total')->label('Total')->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('flags')->label('Perlu ditinjau')->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => SalesLabels::flag((string) $state))
                    ->placeholder('-'),
            ])
            ->defaultSort('device_created_at', 'desc')
            ->filters([
                Filter::make('periode')
                    ->form([
                        DatePicker::make('from')->label('Dari hari bisnis')->default(now((string) config('app.display_timezone'))->subDays(6)->format('Y-m-d')),
                        DatePicker::make('until')->label('Sampai hari bisnis'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        // Selalu batasi rentang agar hanya partisi terkait yang dipindai.
                        ->where('business_date', '>=', $data['from'] ?? CarbonImmutable::now()->subDays(90)->format('Y-m-d'))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('business_date', '<=', $v)))
                    ->indicateUsing(fn (array $data) => ($data['from'] ?? null) ? 'Sejak '.CarbonImmutable::parse($data['from'])->format('d M Y') : null),
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => SalesLabels::outletOptions()),
                SelectFilter::make('status')->label('Status')->options(SalesLabels::ORDER_STATUS),
                TernaryFilter::make('flagged')->label('Perlu ditinjau')
                    ->trueLabel('Hanya yang ditandai')->falseLabel('Tanpa tanda')
                    ->queries(
                        true: fn (Builder $q) => $q->whereRaw("flags <> '[]'::jsonb"),
                        false: fn (Builder $q) => $q->whereRaw("flags = '[]'::jsonb"),
                    ),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Transaksi muncul setelah POS menyinkronkan penjualan.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('receipt_no')->label('No. struk')->fontFamily('mono'),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => SalesLabels::status($state))
                    ->color(fn (string $state) => SalesLabels::statusColor($state)),
                TextEntry::make('business_date')->label('Hari bisnis')->date('d M Y'),
                TextEntry::make('device_created_at')->label('Waktu transaksi')->dateTime('d M Y H.i', $tz),
                TextEntry::make('outlet.name')->label('Outlet'),
                TextEntry::make('cashier.name')->label('Kasir')->placeholder('-'),
                TextEntry::make('channel_code')->label('Channel')->formatStateUsing(fn (string $state) => SalesLabels::channel($state)),
                TextEntry::make('queue_no')->label('No. antrean')->placeholder('-'),
                TextEntry::make('table_label')->label('Meja')->placeholder('-'),
                TextEntry::make('customer_name')->label('Pelanggan')->placeholder('-'),
                TextEntry::make('server_received_at')->label('Diterima server')->dateTime('d M Y H.i', $tz),
                TextEntry::make('flags')->label('Perlu ditinjau')->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => SalesLabels::flag((string) $state))->placeholder('-'),
                TextEntry::make('note')->label('Catatan')->placeholder('-')->columnSpanFull(),
            ]),
            Section::make('Pembatalan')
                ->visible(fn (Order $record) => $record->status === Order::VOIDED)
                ->columns(3)
                ->schema([
                    TextEntry::make('voided_at')->label('Waktu')->dateTime('d M Y H.i', $tz),
                    TextEntry::make('void_reason')->label('Alasan'),
                    TextEntry::make('void_authorized_by')->label('Disetujui')
                        ->formatStateUsing(fn (?string $state) => $state === null ? '-' : (User::query()->whereKey($state)->value('name') ?? '-'))
                        ->placeholder('-'),
                ]),
            ViewEntry::make('detail')->view('filament.infolists.order-detail')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
