<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountingPeriodResource\Pages;
use App\Filament\Support\AccountingAccess;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\PeriodService;
use App\Modules\Accounting\Domain\Models\AccountingPeriod;
use App\Modules\Identity\Domain\Models\User;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Periode akuntansi bulanan (ACC-04). Periode dibuat sendiri saat jurnal pertama bulan itu dibuat. */
class AccountingPeriodResource extends Resource
{
    protected static ?string $model = AccountingPeriod::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $modelLabel = 'periode';

    protected static ?string $pluralModelLabel = 'Periode Akuntansi';

    protected static ?string $slug = 'pembukuan/periode';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return AccountingAccess::canView();
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->label('Tahun')->sortable(),
                TextColumn::make('month')->label('Bulan')
                    ->formatStateUsing(fn (AccountingPeriod $record) => $record->label()),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => AccountingPeriod::STATUS_LABEL[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        AccountingPeriod::OPEN => 'success',
                        AccountingPeriod::SOFT_CLOSED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('d M Y H.i')->placeholder('-'),
                TextColumn::make('close_note')->label('Keterangan')->wrap()->placeholder('-'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Status')->options(AccountingPeriod::STATUS_LABEL),
            ])
            ->actions([
                Action::make('softClose')
                    ->label('Tutup sementara')->icon('heroicon-o-clock')->color('warning')
                    ->visible(fn (AccountingPeriod $record) => $record->isOpen() && AccountingAccess::canManage())
                    ->modalHeading('Tutup sementara (soft close)')
                    ->modalDescription('Laporan periode ini dianggap terbit, tetapi koreksi yang memang milik bulan ini masih boleh masuk — dan setiap posting ke periode ini tercatat khusus di jejak audit.')
                    ->form([Textarea::make('note')->label('Keterangan (opsional)')->maxLength(300)->rows(2)])
                    ->action(fn (AccountingPeriod $record, array $data) => self::run(
                        fn (User $by) => app(PeriodService::class)->close($record, $by, $data['note'] ?? null, hard: false), 'Periode ditutup sementara.')),
                Action::make('close')
                    ->label('Tutup permanen')->icon('heroicon-o-lock-closed')->color('danger')
                    ->visible(fn (AccountingPeriod $record) => ! $record->isHardClosed() && AccountingAccess::canManage())
                    ->modalHeading('Tutup periode secara permanen')
                    ->modalDescription('Setelah ini tidak ada jurnal baru yang bisa masuk ke periode ini sama sekali. Jurnal yang belum diposting harus diselesaikan lebih dulu.')
                    ->form([Textarea::make('note')->label('Keterangan (opsional)')->maxLength(300)->rows(2)])
                    ->action(fn (AccountingPeriod $record, array $data) => self::run(
                        fn (User $by) => app(PeriodService::class)->close($record, $by, $data['note'] ?? null), 'Periode ditutup permanen.')),
                Action::make('reopen')
                    ->label('Buka kembali')->icon('heroicon-o-lock-open')->color('danger')
                    ->visible(fn (AccountingPeriod $record) => $record->isClosed() && AccountingAccess::canManage())
                    ->modalHeading('Buka kembali periode')
                    ->modalDescription('Laporan periode ini mungkin sudah terbit. Alasannya dicatat di audit log.')
                    ->form([Textarea::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)->rows(2)])
                    ->action(fn (AccountingPeriod $record, array $data) => self::run(
                        fn (User $by) => app(PeriodService::class)->reopen($record, $by, (string) $data['reason']), 'Periode dibuka kembali.')),
            ])
            ->emptyStateHeading('Belum ada periode')
            ->emptyStateDescription('Periode dibuat sendiri saat jurnal pertama bulan itu dicatat.');
    }

    /** @param  callable(User): mixed  $do */
    private static function run(callable $do, string $sukses): void
    {
        $user = AccountingAccess::user();
        if ($user === null || ! AccountingAccess::canManage()) {
            Notification::make()->danger()->title('Tidak berwenang')->send();

            return;
        }
        try {
            $do($user);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Tidak dapat diproses')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($sukses)->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAccountingPeriods::route('/')];
    }
}
