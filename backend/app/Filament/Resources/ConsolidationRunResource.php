<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConsolidationRunResource\Pages;
use App\Filament\Resources\ConsolidationRunResource\RelationManagers\AdjustmentsRelationManager;
use App\Filament\Support\ConsolidationAccess;
use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Domain\Models\ConsolidationEntity;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Proses konsolidasi: satu grup, satu periode (CON-01).
 *
 * Layar ini bukan tempat angka dibaca — itu di kertas kerja dan dua laporan konsolidasi. Di sini
 * hanya tiga keputusan yang dibuat: **tarik saldo**, **kunci final**, dan **buka kembali**. Ketiganya
 * dipisahkan dari layar laporan dengan sengaja, supaya membuka laporan tidak pernah bisa mengubah
 * angka yang sedang dibaca orang lain.
 */
class ConsolidationRunResource extends Resource
{
    protected static ?string $model = ConsolidationRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Holding & Konsolidasi';

    protected static ?string $navigationLabel = 'Proses Konsolidasi';

    protected static ?string $modelLabel = 'proses konsolidasi';

    protected static ?string $pluralModelLabel = 'proses konsolidasi';

    protected static ?string $slug = 'konsolidasi/proses';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return ConsolidationAccess::canView();
    }

    public static function canCreate(): bool
    {
        return ConsolidationAccess::canManage();
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $now = CarbonImmutable::now(config('app.display_timezone'));

        return $form->schema([
            Placeholder::make('penjelasan')
                ->label('Periode konsolidasi')
                ->content('Satu grup hanya punya satu proses per periode. Membuat proses untuk periode '
                    .'yang sudah ada akan membuka proses itu, bukan membuat yang kedua — dua jawaban '
                    .'atas satu pertanyaan tidak bisa dipilih siapa pun.')
                ->columnSpanFull(),
            DatePicker::make('period_start')->label('Awal periode')->required()
                ->default($now->startOfMonth()->format('Y-m-d')),
            DatePicker::make('period_end')->label('Akhir periode')->required()
                ->default($now->endOfMonth()->format('Y-m-d')),
            TextInput::make('label')->label('Nama periode')->maxLength(120)
                ->placeholder($now->translatedFormat('F Y'))
                ->helperText('Hanya untuk memudahkan membacanya di daftar.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Nomor')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('period_start')->label('Periode')->sortable()
                    ->formatStateUsing(fn ($state, ConsolidationRun $record) => $record->periodLabel()),
                TextColumn::make('label')->label('Nama')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => ConsolidationRun::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => $state === ConsolidationRun::FINAL ? 'success' : 'warning'),
                TextColumn::make('entity_count')->label('Entitas')->alignRight(),
                TextColumn::make('generated_at')->label('Saldo ditarik')->dateTime('d M Y H:i')
                    ->placeholder('Belum pernah')
                    ->description(fn (ConsolidationRun $record) => $record->generated_at === null
                        ? 'Laporannya masih kosong' : null),
            ])
            ->defaultSort('period_end', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(ConsolidationRun::STATUS_LABEL),
            ])
            ->actions([
                ViewAction::make()->label('Detail'),
                Action::make('generate')
                    ->label('Tarik saldo entitas')->icon('heroicon-o-arrow-path')->color('primary')
                    ->visible(fn (ConsolidationRun $record) => $record->isDraft() && ConsolidationAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Tarik ulang saldo seluruh entitas')
                    ->modalDescription('Saldo tiap entitas anggota dibaca ulang dan menimpa snapshot sebelumnya. '
                        .'Ayat eliminasi yang sudah dientri TIDAK terhapus — yang berubah angka entitasnya, '
                        .'bukan keputusan akuntannya.')
                    ->modalSubmitActionLabel('Tarik saldo')
                    ->action(fn (ConsolidationRun $record) => self::run(
                        fn () => app(ConsolidationService::class)->generate($record),
                        'Saldo entitas ditarik ulang.')),
                Action::make('finalize')
                    ->label('Kunci final')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (ConsolidationRun $record) => $record->isDraft() && ConsolidationAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Kunci periode ini sebagai final')
                    ->modalDescription('Setelah final, saldo tidak ditarik ulang lagi — termasuk oleh proses '
                        .'terjadwal harian. Masih bisa dibuka kembali, tetapi alasannya akan tercatat.')
                    ->modalSubmitActionLabel('Kunci final')
                    ->action(fn (ConsolidationRun $record) => self::run(
                        fn () => app(ConsolidationService::class)->finalize($record),
                        'Periode dikunci sebagai final.')),
                Action::make('reopen')
                    ->label('Buka kembali')->icon('heroicon-o-lock-open')->color('danger')
                    ->visible(fn (ConsolidationRun $record) => ! $record->isDraft() && ConsolidationAccess::canManage())
                    ->modalHeading('Buka kembali periode final')
                    ->modalDescription('Angka grup periode ini mungkin sudah dilaporkan ke pemilik. '
                        .'Alasannya wajib dan akan tercatat di jejak audit.')
                    ->form([
                        Textarea::make('reason')->label('Alasan membuka kembali')
                            ->required()->minLength(5)->maxLength(500)->rows(3),
                    ])
                    ->modalSubmitActionLabel('Buka kembali')
                    ->action(fn (ConsolidationRun $record, array $data) => self::run(
                        fn () => app(ConsolidationService::class)->reopen($record, (string) $data['reason']),
                        'Periode dibuka kembali.')),
            ])
            ->emptyStateHeading('Belum ada proses konsolidasi')
            ->emptyStateDescription('Buat satu untuk periode yang ingin dikonsolidasi, lalu tarik saldo '
                .'entitas anggotanya. Sesudah itu kertas kerja dan laporan konsolidasi langsung terisi.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Nomor')->fontFamily('mono'),
                TextEntry::make('group.name')->label('Grup'),
                TextEntry::make('period_start')->label('Periode')
                    ->formatStateUsing(fn ($state, ConsolidationRun $record) => $record->periodLabel()),
                TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => ConsolidationRun::STATUS_LABEL[$state] ?? $state),
                TextEntry::make('generated_at')->label('Saldo ditarik')->dateTime('d M Y H:i')->placeholder('Belum pernah'),
                TextEntry::make('generatedBy.name')->label('Oleh')->placeholder('-'),
                TextEntry::make('finalized_at')->label('Dikunci')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('finalizedBy.name')->label('Oleh')->placeholder('-'),
            ]),
            Section::make('Entitas yang ikut')
                ->description('Keadaan buku tiap entitas pada saat saldo terakhir ditarik. '
                    .'Keanggotaan grup diatur lewat perintah konsolidasi:grup, bukan dari layar — '
                    .'memilih entitas dari daftar berarti layar ini harus membaca daftar entitas lain.')
                ->schema([
                    TextEntry::make('entities')
                        ->label('')
                        ->listWithLineBreaks()
                        ->state(fn (ConsolidationRun $record) => $record->entities
                            ->map(fn (ConsolidationEntity $e): string => $e->source_code.' — '.$e->source_name
                                .' · '.$e->posted_journal_count.' jurnal terposting'
                                .($e->draft_journal_count > 0 ? ', '.$e->draft_journal_count.' belum diposting' : '')
                                .($e->out_of_balance ? ' · BUKU TIDAK SEIMBANG' : ''))
                            ->all())
                        ->placeholder('Saldo belum pernah ditarik.'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [AdjustmentsRelationManager::class];
    }

    public static function run(callable $do, string $sukses): void
    {
        if (! ConsolidationAccess::canManage()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do();
        } catch (ConsolidationException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConsolidationRuns::route('/'),
            'create' => Pages\CreateConsolidationRun::route('/baru'),
            'view' => Pages\ViewConsolidationRun::route('/{record}'),
        ];
    }
}
