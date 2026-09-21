<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportScheduleResource\Pages;
use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportScheduler;
use App\Modules\Reporting\Domain\Models\ReportDelivery;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Tenancy\Application\WritableCompany;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Jadwal pengiriman laporan via email (FR-RPT-08). */
class ReportScheduleResource extends Resource
{
    protected static ?string $model = ReportSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Jadwal Email';

    protected static ?string $modelLabel = 'jadwal laporan';

    protected static ?string $pluralModelLabel = 'Jadwal Laporan Email';

    protected static ?string $slug = 'laporan/jadwal-email';

    protected static ?int $navigationSort = 7;

    private static function scheduler(): ReportScheduler
    {
        return app(ReportScheduler::class);
    }

    public static function form(Form $form): Form
    {
        $user = ReportPage::user();
        $tz = ReportAccess::timezone();

        return $form->schema([
            Section::make('Laporan')->columns(3)->schema([
                TextInput::make('name')->label('Nama jadwal')->required()->maxLength(100)->columnSpan(3)
                    ->placeholder('Penjualan harian untuk pemilik'),
                Select::make('report_key')->label('Laporan')->required()->searchable()->columnSpan(2)
                    ->options(fn () => $user === null ? [] : self::scheduler()->reportOptions($user))->live(),
                Select::make('format')->label('Format lampiran')->required()->options(ReportExporter::FORMATS)->default('xlsx')->selectablePlaceholder(false),
                Select::make('brand_id')->label('Brand')->placeholder('Semua brand')->live()
                    ->options(fn (Get $get) => $user === null || ! $get('report_key') ? [] : app(ReportAccess::class)->brandOptions($user, ReportCatalog::kind((string) $get('report_key'))))
                    ->afterStateUpdated(fn (callable $set) => $set('outlet_id', null)),
                Select::make('outlet_id')->label('Outlet')->placeholder('Semua outlet')
                    ->options(fn (Get $get) => $user === null || ! $get('report_key') ? [] : app(ReportAccess::class)->outletOptions($user, ReportCatalog::kind((string) $get('report_key')), $get('brand_id'))),
            ]),
            Section::make('Pengiriman')->columns(3)->schema([
                Select::make('frequency')->label('Frekuensi')->required()->options(ReportSchedule::FREQUENCIES)->default('daily')->selectablePlaceholder(false)->live(),
                TimePicker::make('send_time')->label('Jam kirim')->required()->seconds(false)->native()->default('07:00')
                    ->helperText("Zona waktu {$tz}"),
                Placeholder::make('period_hint')->label('Periode laporan')
                    ->content(fn (Get $get) => match ($get('frequency')) {
                        'weekly' => 'Dikirim setiap Senin untuk Senin–Minggu sebelumnya.',
                        'monthly' => 'Dikirim setiap tanggal 1 untuk bulan sebelumnya.',
                        default => 'Dikirim setiap hari untuk penjualan kemarin.',
                    }),
                TagsInput::make('recipients')->label('Email penerima')->required()->columnSpan(3)
                    ->placeholder('Ketik alamat email lalu tekan Enter')
                    ->helperText('Maks. '.ReportSchedule::MAX_RECIPIENTS.' alamat. Laporan dikirim sesuai hak akses Anda sebagai pembuat jadwal.')
                    ->nestedRecursiveRules(['email:rfc', 'max:150'])
                    ->rules(['array', 'max:'.ReportSchedule::MAX_RECIPIENTS])
                    ->default(fn () => $user?->email !== null ? [$user->email] : []),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = ReportAccess::timezone();

        return $infolist->schema([
            InfoSection::make()->columns(4)->schema([
                TextEntry::make('report_key')->label('Laporan')->formatStateUsing(fn (string $state) => ReportCatalog::label($state))->columnSpan(2),
                TextEntry::make('format')->label('Format')->formatStateUsing(fn (string $state) => ReportExporter::FORMATS[$state] ?? $state),
                TextEntry::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextEntry::make('frequency')->label('Frekuensi')->formatStateUsing(fn (string $state) => ReportSchedule::FREQUENCIES[$state] ?? $state),
                TextEntry::make('send_time')->label('Jam kirim')->formatStateUsing(fn (string $state) => substr($state, 0, 5).' '.$tz),
                TextEntry::make('next_run_at')->label('Kiriman berikutnya')->dateTime('d M Y H.i', $tz)->placeholder('-'),
                TextEntry::make('owner.name')->label('Pembuat')->placeholder('-'),
                TextEntry::make('recipients')->label('Penerima')->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : (string) $state)->columnSpan(2),
                TextEntry::make('disabled_reason')->label('Keterangan')->placeholder('-')->columnSpan(2),
            ]),
            InfoSection::make('Riwayat pengiriman')->schema([
                ViewEntry::make('id')->hiddenLabel()->view('filament.infolists.report-deliveries'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = ReportAccess::timezone();

        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->weight('medium')
                    ->description(fn (ReportSchedule $r) => ReportCatalog::label($r->report_key)),
                TextColumn::make('frequency')->label('Frekuensi')
                    ->formatStateUsing(fn (string $state, ReportSchedule $r) => (ReportSchedule::FREQUENCIES[$state] ?? $state).' · '.substr($r->send_time, 0, 5)),
                TextColumn::make('recipients')->label('Penerima')
                    ->formatStateUsing(fn ($state, ReportSchedule $r) => count($r->recipients).' alamat'),
                TextColumn::make('owner.name')->label('Pembuat'),
                TextColumn::make('last_status')->label('Kiriman terakhir')->badge()->placeholder('Belum pernah')
                    ->formatStateUsing(fn (?string $state) => ReportDelivery::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('next_run_at')->label('Berikutnya')->dateTime('d M Y H.i', $tz)->placeholder('Nonaktif'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
            ])
            ->actions([
                ViewAction::make()->label('Detail'),
                Action::make('toggle')
                    ->label(fn (ReportSchedule $r) => $r->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (ReportSchedule $r) => $r->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ReportSchedule $r) => ($user = ReportPage::user()) !== null
                        && ($r->is_active ? self::scheduler()->canToggle($user, $r) : self::scheduler()->canEdit($user, $r)))
                    ->action(function (ReportSchedule $r): void {
                        $user = ReportPage::user();
                        abort_if($user === null, 403);
                        self::scheduler()->setActive($user, $r, ! $r->is_active);
                        Notification::make()->title($r->is_active ? 'Jadwal diaktifkan.' : 'Jadwal dinonaktifkan.')->success()->send();
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada jadwal laporan')
            ->emptyStateDescription('Buat jadwal agar laporan terkirim otomatis ke email setiap hari, minggu, atau bulan.');
    }

    /** @return Builder<ReportSchedule> */
    public static function getEloquentQuery(): Builder
    {
        $user = ReportPage::user();

        return parent::getEloquentQuery()->with('owner:id,name')
            ->when($user === null || ! self::scheduler()->canViewAll($user), fn (Builder $q) => $q->where('created_by', $user?->id));
    }

    public static function canViewAny(): bool
    {
        $user = ReportPage::user();

        return $user !== null && (self::scheduler()->canUse($user) || self::scheduler()->canViewAll($user));
    }

    public static function canCreate(): bool
    {
        $user = ReportPage::user();

        return $user !== null && self::scheduler()->canUse($user) && WritableCompany::allows();
    }

    public static function canView(Model $record): bool
    {
        $user = ReportPage::user();

        return $user !== null && $record instanceof ReportSchedule
            && ($record->created_by === $user->id || self::scheduler()->canViewAll($user));
    }

    public static function canEdit(Model $record): bool
    {
        $user = ReportPage::user();

        return $user !== null && $record instanceof ReportSchedule && self::scheduler()->canEdit($user, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReportSchedules::route('/'),
            'create' => Pages\CreateReportSchedule::route('/create'),
            'view' => Pages\ViewReportSchedule::route('/{record}'),
            'edit' => Pages\EditReportSchedule::route('/{record}/edit'),
        ];
    }
}
