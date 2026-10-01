<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApprovalRuleResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\PaymentAccess;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Batas wewenang persetujuan (DOC-09).
 *
 * Satu baris = satu tanda tangan yang dituntut pada satu band nilai. Band "tanpa batas" ditulis
 * dengan batas kosong, dan ialah yang menampung nilai di atas semua band berbatas.
 *
 * Layarnya sengaja mentah — tabel baris, bukan penyihir berlangkah. Yang mengubah matriks ini adalah
 * orang yang sudah tahu persis kebijakan tanda tangannya; yang ia butuhkan adalah melihat seluruh
 * matriks sekaligus untuk memastikan tidak ada band yang bolong, bukan dituntun satu pertanyaan
 * demi satu pertanyaan.
 */
class ApprovalRuleResource extends Resource
{
    protected static ?string $model = ApprovalRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Dokumen';

    protected static ?string $modelLabel = 'batas wewenang';

    protected static ?string $pluralModelLabel = 'Batas Wewenang';

    protected static ?string $slug = 'dokumen/batas-wewenang';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return PaymentAccess::canView();
    }

    /** Matriks adalah kebijakan, bukan transaksi: hanya yang boleh mencairkan uang yang boleh mengubahnya. */
    public static function canCreate(): bool
    {
        return PaymentAccess::canPay();
    }

    public static function canEdit(Model $record): bool
    {
        return PaymentAccess::canPay();
    }

    public static function canDelete(Model $record): bool
    {
        return PaymentAccess::canPay();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('doc_type')->label('Jenis dokumen')->required()
                ->default(ApprovalRule::PAYMENT_REQUEST)
                ->options(ApprovalRule::DOC_TYPE_LABEL),
            /*
             * Batas ditulis sebagai angka, dan "tanpa batas" sebagai saklar tersendiri. Mengosongkan
             * kolom angka bisa berarti dua hal yang sangat berbeda — belum diisi, atau tanpa batas —
             * dan pada kebijakan pengeluaran uang perbedaannya tidak boleh ditebak.
             */
            Toggle::make('unlimited')->label('Tanpa batas nilai')->live()
                ->dehydrated(false)
                ->helperText('Band yang menampung nilai di atas semua band berbatas.')
                ->afterStateHydrated(fn (Toggle $component, ?Model $record) => $component->state(
                    $record instanceof ApprovalRule ? $record->max_amount === null : false)),
            TextInput::make('max_amount')->label('Sampai nilai (Rp)')->numeric()->minValue(1)
                ->visible(fn (Get $get) => $get('unlimited') !== true)
                ->required(fn (Get $get) => $get('unlimited') !== true)
                ->helperText('Pengajuan dengan nilai sampai angka ini masuk band ini.'),
            TextInput::make('level')->label('Tingkat tanda tangan')->numeric()->required()
                ->minValue(1)->maxValue(5)->default(1)
                ->helperText('Tingkat 1 menandatangani lebih dulu, lalu 2, dan seterusnya.'),
            Select::make('role')->label('Peran penandatangan')->required()
                ->options(fn () => ApprovalMatrix::roleOptions()),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('doc_type')->label('Dokumen')
                    ->formatStateUsing(fn (string $state) => ApprovalRule::DOC_TYPE_LABEL[$state] ?? $state),
                TextColumn::make('max_amount')->label('Sampai nilai')->alignEnd()
                    // Band tanpa batas disebut namanya, bukan dibiarkan kosong: baris kosong terbaca
                    // sebagai data yang belum lengkap.
                    ->formatStateUsing(fn ($state) => $state === null ? 'Tanpa batas' : MenuFields::rupiah((string) $state))
                    ->description(fn (ApprovalRule $record) => $record->max_amount === null ? 'di atas band lain' : null),
                TextColumn::make('level')->label('Tingkat')->alignCenter()->sortable(),
                TextColumn::make('role')->label('Peran')->badge()
                    ->formatStateUsing(fn (string $state) => ApprovalMatrix::roleOptions()[$state] ?? $state),
            ])
            ->defaultSort('level')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw('max_amount ASC NULLS LAST'))
            ->defaultPaginationPageOption(25)
            ->headerActions([
                Action::make('defaults')
                    ->label('Pasang matriks bawaan')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn () => PaymentAccess::canPay() && ApprovalRule::query()->doesntExist())
                    ->requiresConfirmation()
                    ->modalHeading('Pasang matriks bawaan')
                    ->modalDescription('Sampai Rp5 juta: finance. Sampai Rp50 juta: finance lalu admin entitas. '
                        .'Di atasnya: finance, admin entitas, lalu pemilik. Semuanya bisa diubah setelah terpasang.')
                    ->modalSubmitActionLabel('Pasang matriks')
                    ->action(function (): void {
                        $jumlah = app(ApprovalMatrix::class)->installDefaults();
                        Notification::make()->success()->title("Matriks bawaan terpasang ({$jumlah} baris).")->send();
                    }),
            ])
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalDescription('Menghapus baris ini mengubah tanda tangan yang dituntut untuk pengajuan baru. '
                        .'Pengajuan yang sedang berjalan tidak terpengaruh — jumlah tanda tangannya sudah dibekukan saat diajukan.'),
            ])
            ->emptyStateHeading('Matriks belum diatur')
            ->emptyStateDescription('Tanpa matriks, pengajuan pembayaran tidak dapat diajukan. '
                .'Pasang matriks bawaan, lalu sesuaikan.');
    }

    /**
     * Batas band dari isian form: null berarti "tanpa batas".
     *
     * Saklar `unlimited` tidak ikut disimpan (dehydrated(false)), jadi yang menandai band tanpa batas
     * di sini adalah kolom batas yang kosong — dan karena saklar itulah yang menyembunyikan kolomnya,
     * keduanya selalu sejalan.
     *
     * @param  array<string, mixed>  $data
     */
    public static function band(array $data): ?string
    {
        $nilai = $data['max_amount'] ?? null;

        return $nilai === null || $nilai === '' ? null : (string) $nilai;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovalRules::route('/'),
            'create' => Pages\CreateApprovalRule::route('/baru'),
            'edit' => Pages\EditApprovalRule::route('/{record}/ubah'),
        ];
    }
}
