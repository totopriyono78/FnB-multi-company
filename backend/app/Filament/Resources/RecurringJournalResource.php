<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecurringJournalResource\Pages;
use App\Filament\Support\AccountingAccess;
use App\Filament\Support\MenuFields;
use App\Modules\Accounting\Application\RecurringJournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\RecurringJournal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Templat jurnal bulanan (ACC-06): sewa, amortisasi, beban tetap.
 *
 * Templat hanya menghapus pekerjaan mengetik, bukan pekerjaan memeriksa — jurnal yang lahir darinya
 * tetap berstatus draft dan tetap melewati pengajuan seperti jurnal lain.
 */
class RecurringJournalResource extends Resource
{
    protected static ?string $model = RecurringJournal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Akuntansi';

    protected static ?string $modelLabel = 'jurnal berulang';

    protected static ?string $pluralModelLabel = 'Jurnal Berulang';

    protected static ?string $slug = 'pembukuan/jurnal-berulang';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return AccountingAccess::canView();
    }

    public static function canCreate(): bool
    {
        return AccountingAccess::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return AccountingAccess::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return AccountingAccess::canManage();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Nama templat')->required()->maxLength(120)
                ->placeholder('Sewa ruko Kaliurang'),
            TextInput::make('description')->label('Keterangan jurnal')->required()->minLength(3)->maxLength(300)
                ->helperText('Nama bulan ditambahkan sendiri di akhir keterangan.')
                ->columnSpan(2),
            TextInput::make('day_of_month')->label('Tanggal tiap bulan')->numeric()->required()
                ->minValue(1)->maxValue(31)->default(1)
                ->helperText('Tanggal 29–31 jatuh ke hari terakhir pada bulan yang lebih pendek.'),
            DatePicker::make('starts_on')->label('Mulai berlaku')->required()
                ->default(fn () => CarbonImmutable::now(config('app.display_timezone'))->startOfMonth()->format('Y-m-d')),
            DatePicker::make('ends_on')->label('Berakhir')->placeholder('Tanpa batas')
                ->helperText('Isi bila kontraknya punya masa berakhir.'),
            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            Repeater::make('lines')->label('Baris jurnal')->columnSpanFull()
                ->minItems(2)->defaultItems(2)->live(onBlur: true)->addActionLabel('Tambah baris')
                ->schema([
                    Select::make('account_id')->label('Akun')->required()->searchable()->columnSpan(4)
                        ->options(fn () => Account::query()->where('is_postable', true)->where('is_active', true)
                            ->orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->id => $a->label()])->all()),
                    TextInput::make('debit')->label('Debit')->numeric()->minValue(0)->default('0')->live(onBlur: true)->columnSpan(2),
                    TextInput::make('credit')->label('Kredit')->numeric()->minValue(0)->default('0')->live(onBlur: true)->columnSpan(2),
                    Select::make('outlet_id')->label('Outlet')->searchable()->placeholder('Semua')->columnSpan(2)
                        ->options(fn () => Outlet::query()->orderBy('name')->pluck('name', 'id')->all()),
                    TextInput::make('memo')->label('Catatan')->maxLength(300)->columnSpan(2),
                ])->columns(12),
            Placeholder::make('balance')->label('Keseimbangan')->columnSpanFull()
                ->content(function (Get $get): string {
                    [$d, $c] = JournalResource::sides($get('lines') ?? []);
                    if ($d->isZero() && $c->isZero()) {
                        return 'Belum ada nilai yang diisi.';
                    }

                    return $d->isEqualTo($c)
                        ? 'Seimbang — debit dan kredit sama-sama '.MenuFields::rupiah((string) $d->toScale(2))
                        : 'Belum seimbang · selisih '.MenuFields::rupiah((string) $d->minus($c)->abs()->toScale(2));
                }),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Templat')->searchable()->wrap(),
                TextColumn::make('day_of_month')->label('Tiap tanggal')->alignCenter(),
                TextColumn::make('starts_on')->label('Mulai')->date('d M Y'),
                TextColumn::make('ends_on')->label('Berakhir')->date('d M Y')->placeholder('Tanpa batas'),
                TextColumn::make('last_generated_on')->label('Terakhir dibuat')->date('d M Y')->placeholder('Belum pernah'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([
                EditAction::make(),
                Action::make('runNow')
                    ->label('Buat sekarang')->icon('heroicon-o-play')->color('success')
                    ->visible(fn () => AccountingAccess::canManage())
                    ->requiresConfirmation()
                    ->modalHeading('Buat jurnal dari templat ini')
                    ->modalDescription('Jurnal draft dibuat untuk bulan yang sudah jatuh tempo tetapi belum pernah dibuat. Bulan yang sudah ada tidak dibuat ulang.')
                    ->action(function (RecurringJournal $record): void {
                        $user = AccountingAccess::user();
                        if (! $user instanceof User || ! AccountingAccess::canManage()) {
                            Notification::make()->danger()->title('Tidak berwenang')->send();

                            return;
                        }
                        $jumlah = app(RecurringJournalService::class)
                            ->run(CarbonImmutable::now(config('app.display_timezone'))->startOfDay(), $user);
                        Notification::make()->success()
                            ->title($jumlah === 0 ? 'Tidak ada yang perlu dibuat' : "{$jumlah} jurnal draft dibuat")
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Belum ada templat')
            ->emptyStateDescription('Buat templat untuk beban yang nilainya sama tiap bulan — sewa, amortisasi, penyusutan.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecurringJournals::route('/'),
            'create' => Pages\CreateRecurringJournal::route('/baru'),
            'edit' => Pages\EditRecurringJournal::route('/{record}/ubah'),
        ];
    }
}
