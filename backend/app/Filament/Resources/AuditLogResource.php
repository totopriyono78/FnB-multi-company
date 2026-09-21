<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\RoleScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Device;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Pencarian audit log (FR-AUD-02). */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Keamanan';

    protected static ?string $modelLabel = 'catatan audit';

    protected static ?string $pluralModelLabel = 'Audit Log';

    protected static ?string $slug = 'audit-log';

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'authorizer']))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('action')->label('Aksi')->searchable()->fontFamily('mono'),
                TextColumn::make('auditable_type')->label('Data')->placeholder('-'),
                TextColumn::make('user.name')->label('Oleh')->placeholder('Sistem')->searchable(),
                TextColumn::make('authorizer.name')->label('Disetujui')->placeholder('-')->toggleable(),
                TextColumn::make('reason')->label('Alasan')->limit(40)->placeholder('-')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('user_id')->label('User')
                    ->options(fn () => DB::table('company_users')
                        ->join('users', 'users.id', '=', 'company_users.user_id')
                        ->where('company_users.company_id', filament()->getTenant()?->getKey())
                        ->orderBy('users.name')
                        ->pluck('users.name', 'users.id')
                        ->all())
                    ->searchable(),
                SelectFilter::make('auditable_type')->label('Jenis data')->options([
                    'Brand' => 'Brand', 'Outlet' => 'Outlet', 'Device' => 'Perangkat',
                    'CompanyUser' => 'Staf', 'Company' => 'Company', 'Role' => 'Role',
                ]),
                Filter::make('periode')->form([
                    DatePicker::make('from')->label('Dari tanggal'),
                    DatePicker::make('until')->label('Sampai tanggal'),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v, $tz)->startOfDay()->utc()))
                    ->when($data['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', CarbonImmutable::parse($v, $tz)->endOfDay()->utc()))),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada catatan');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H.i.s', (string) config('app.display_timezone')),
                TextEntry::make('action')->label('Aksi'),
                TextEntry::make('user.name')->label('Oleh')->placeholder('Sistem'),
                TextEntry::make('authorizer.name')->label('Disetujui oleh')->placeholder('-'),
                TextEntry::make('auditable_type')->label('Data')->placeholder('-'),
                TextEntry::make('auditable_id')->label('ID data')->placeholder('-')->fontFamily('mono'),
                TextEntry::make('reason')->label('Alasan')->placeholder('-')->columnSpanFull(),
                TextEntry::make('ip_address')->label('Alamat IP')->placeholder('-'),
                TextEntry::make('request_id')->label('Request ID')->placeholder('-')->fontFamily('mono'),
            ]),
            Section::make('Perubahan')->columns(2)->schema([
                KeyValueEntry::make('old_values')->label('Sebelum')->placeholder('-')
                    ->getStateUsing(fn (AuditLog $r) => self::stringify($r->old_values)),
                KeyValueEntry::make('new_values')->label('Sesudah')->placeholder('-')
                    ->getStateUsing(fn (AuditLog $r) => self::stringify($r->new_values)),
                KeyValueEntry::make('metadata')->label('Keterangan')->placeholder('-')->columnSpanFull()
                    ->getStateUsing(fn (AuditLog $r) => self::stringify($r->metadata)),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, string>|null
     */
    private static function stringify(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return collect($values)->map(fn ($v) => is_scalar($v) || $v === null ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : (string) json_encode($v, JSON_UNESCAPED_UNICODE))->all();
    }

    /** @return Builder<AuditLog> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = auth()->user();
        $mine = $actor instanceof User ? app(AccessScope::class)->for($actor) : null;

        if ($mine !== null) {
            $deviceIds = Device::query()->whereIn('outlet_id', $mine['outlets'])->pluck('id');
            $userIds = DB::table('company_users')
                ->join('role_scopes', 'role_scopes.company_user_id', '=', 'company_users.id')
                ->where('role_scopes.scope_type', RoleScope::OUTLET)
                ->whereIn('role_scopes.scope_id', $mine['outlets'])
                ->pluck('company_users.user_id');
            $query->where(fn (Builder $q) => $q->whereIn('device_id', $deviceIds)->orWhereIn('user_id', $userIds));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
