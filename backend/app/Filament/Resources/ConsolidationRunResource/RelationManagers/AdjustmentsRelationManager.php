<?php

namespace App\Filament\Resources\ConsolidationRunResource\RelationManagers;

use App\Filament\Resources\ConsolidationRunResource;
use App\Filament\Support\ConsolidationAccess;
use App\Filament\Support\MenuFields;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Domain\Models\ConsolidationAdjustment;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Ayat eliminasi & penyesuaian di level konsolidasi (bagian manual dari CON-05).
 *
 * **Satu baris = satu ayat berpasangan.** Formulirnya tidak punya tombol "tambah baris", dan itu
 * bukan kekurangan: jurnal eliminasi berbaris-baris akan selalu bisa timpang, dan ketimpangannya
 * baru terlihat sebagai neraca konsolidasi yang aneh — jauh dari tempat kesalahannya dibuat. Dengan
 * bentuk berpasangan, ketimpangan tidak mungkin ada karena tidak ada tempat untuk menyimpannya.
 * Eliminasi yang butuh lebih dari dua sisi ditulis sebagai dua ayat.
 */
class AdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'adjustments';

    protected static ?string $title = 'Eliminasi & penyesuaian';

    protected static ?string $modelLabel = 'ayat';

    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('penjelasan')
                ->label('Satu ayat, dua sisi')
                ->content('Pilih akun yang didebit dan akun yang dikredit. Untuk menghapus penjualan '
                    .'antar entitas, debit akun pendapatannya dan kredit akun bebannya sebesar nilai '
                    .'yang sama — keduanya kembali ke nol di laporan konsolidasi.')
                ->columnSpanFull(),
            Select::make('kind')->label('Jenis')->required()
                ->options(ConsolidationAdjustment::KIND_LABEL)
                ->default(ConsolidationAdjustment::ELIMINATION)
                ->native(false),
            TextInput::make('amount')->label('Nilai')->required()->numeric()->minValue(1)->prefix('Rp'),
            Select::make('debit_account_id')->label('Akun debit')->required()
                ->options(fn () => self::accountOptions())->searchable()->native(false),
            Select::make('credit_account_id')->label('Akun kredit')->required()
                ->options(fn () => self::accountOptions())->searchable()->native(false),
            TextInput::make('description')->label('Keterangan')->required()->maxLength(300)
                ->placeholder('Eliminasi penjualan antar entitas')
                ->helperText('Wajib: eliminasi tanpa keterangan tidak bisa ditelusuri siapa pun setahun kemudian.')
                ->columnSpanFull(),
            TextInput::make('counterparty_note')->label('Entitas yang terlibat')->maxLength(200)
                ->placeholder('Pusat ↔ Kaliurang')
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#')->alignRight(),
                TextColumn::make('kind')->label('Jenis')->badge()
                    ->formatStateUsing(fn (string $state) => ConsolidationAdjustment::KIND_LABEL[$state] ?? $state),
                TextColumn::make('debitAccount.code')->label('Debit')->fontFamily('mono')
                    ->description(fn (ConsolidationAdjustment $record) => $record->debitAccount?->name),
                TextColumn::make('creditAccount.code')->label('Kredit')->fontFamily('mono')
                    ->description(fn (ConsolidationAdjustment $record) => $record->creditAccount?->name),
                TextColumn::make('amount')->label('Nilai')->alignRight()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('description')->label('Keterangan')->wrap()
                    ->description(fn (ConsolidationAdjustment $record) => $record->counterparty_note),
            ])
            ->defaultSort('sequence')
            ->headerActions([
                CreateAction::make()
                    ->label('Ayat baru')
                    ->visible(fn () => $this->canManage())
                    ->modalHeading('Ayat eliminasi / penyesuaian')
                    ->modalSubmitActionLabel('Simpan ayat')
                    ->using(function (array $data) {
                        $result = null;
                        ConsolidationRunResource::run(function () use ($data, &$result): void {
                            $result = app(ConsolidationService::class)->addAdjustment($this->ownerRun(), $data);
                        }, 'Ayat tersimpan.');

                        return $result ?? new ConsolidationAdjustment;
                    }),
            ])
            ->actions([
                DeleteAction::make()->label('Hapus')
                    ->visible(fn () => $this->canManage())
                    ->modalHeading('Hapus ayat ini')
                    ->using(function (ConsolidationAdjustment $record): void {
                        ConsolidationRunResource::run(
                            fn () => app(ConsolidationService::class)->deleteAdjustment($record),
                            'Ayat dihapus.');
                    }),
                Action::make('locked')
                    ->label('Terkunci')->icon('heroicon-o-lock-closed')->color('gray')->disabled()
                    ->visible(fn () => ! $this->ownerRun()->isDraft()),
            ])
            ->emptyStateHeading('Belum ada ayat eliminasi')
            ->emptyStateDescription('Transaksi antar entitas — penjualan, talangan, sewa antar perusahaan — '
                .'dihapus di sini supaya tidak terhitung dua kali di laporan grup. Eliminasi otomatis '
                .'menyusul bersama advis tagih dan transfer kas antar entitas.');
    }

    /**
     * Filament menjadikan relation manager pada halaman "Detail" hanya-baca secara bawaan, dan di
     * situlah layar ini memang berada: proses konsolidasi tidak punya halaman "Ubah", karena tidak
     * satu pun kolomnya boleh diubah setelah dibuat. Tanpa penimpaan ini tombol "Ayat baru" hilang
     * tanpa satu pun galat — jenis kegagalan paling sulit dilacak, karena izinnya benar, kewenangannya
     * benar, dan tetap tidak ada apa pun yang bisa diklik.
     *
     * Kewenangan nyatanya tetap dijaga `canCreate()`/`canDelete()` di bawah.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /*
     * Dua izin ini harus ditimpa: bawaannya bertanya kepada policy model, dan tanpa policy jawabannya
     * "tidak" — tombolnya hilang tanpa satu pun galat, yang justru jenis kegagalan paling sulit
     * dilacak. Keduanya menunjuk aturan yang sama dengan sisa modul ini.
     */
    protected function canCreate(): bool
    {
        return $this->canManage();
    }

    protected function canDelete(Model $record): bool
    {
        return $this->canManage();
    }

    private function ownerRun(): ConsolidationRun
    {
        /** @var ConsolidationRun $record */
        $record = $this->getOwnerRecord();

        return $record;
    }

    private function canManage(): bool
    {
        return ConsolidationAccess::canManage() && $this->ownerRun()->isDraft();
    }

    /** @return array<string, string> */
    private static function accountOptions(): array
    {
        return Account::query()->where('is_postable', true)->where('is_active', true)
            ->orderBy('code')->get()
            ->mapWithKeys(fn (Account $account): array => [$account->id => $account->label()])
            ->all();
    }
}
