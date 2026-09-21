<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use App\Filament\Support\MenuFields;
use App\Filament\Support\SalesLabels;
use App\Modules\Sales\Domain\Models\Shift;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Riwayat shift kasir & rekap kas (FR-POS-02, FR-POS-04). */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $modelLabel = 'shift';

    protected static ?string $pluralModelLabel = 'Shift Kasir';

    protected static ?string $slug = 'shift';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return SalesLabels::canView();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Shift && in_array($record->outlet_id, SalesLabels::outletIds(), true);
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

    /** @return Builder<Shift> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('outlet_id', SalesLabels::outletIds());
    }

    public static function table(Table $table): Table
    {
        $tz = (string) config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['outlet:id,name,code', 'cashier:id,name', 'device:id,code,name']))
            ->columns([
                TextColumn::make('opened_at')->label('Dibuka')->dateTime('d M Y H.i', $tz)->sortable(),
                TextColumn::make('closed_at')->label('Ditutup')->dateTime('d M Y H.i', $tz)->placeholder('Masih terbuka'),
                TextColumn::make('outlet.name')->label('Outlet'),
                TextColumn::make('device.code')->label('Perangkat')->fontFamily('mono'),
                TextColumn::make('cashier.name')->label('Kasir')->placeholder('-'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => $state === Shift::OPEN ? 'Terbuka' : 'Ditutup')
                    ->color(fn (string $state) => $state === Shift::OPEN ? 'warning' : 'success'),
                TextColumn::make('expected_cash')->label('Kas seharusnya')->alignEnd()->placeholder('-')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('cash_variance')->label('Selisih')->alignEnd()->placeholder('-')
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state))
                    ->color(fn ($state) => $state !== null && (float) $state !== 0.0 ? 'danger' : null),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                SelectFilter::make('outlet_id')->label('Outlet')->options(fn () => SalesLabels::outletOptions()),
                SelectFilter::make('status')->label('Status')->options([Shift::OPEN => 'Terbuka', Shift::CLOSED => 'Ditutup']),
            ])
            ->actions([ViewAction::make()->label('Detail')])
            ->emptyStateHeading('Belum ada shift')
            ->emptyStateDescription('Shift dibuka kasir dari aplikasi POS.');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $tz = (string) config('app.display_timezone');
        $money = fn ($state) => MenuFields::rupiah((string) $state);

        return $infolist->schema([
            Section::make()->columns(4)->schema([
                TextEntry::make('outlet.name')->label('Outlet'),
                TextEntry::make('device.name')->label('Perangkat'),
                TextEntry::make('cashier.name')->label('Kasir')->placeholder('-'),
                TextEntry::make('business_date')->label('Hari bisnis')->date('d M Y'),
                TextEntry::make('opened_at')->label('Dibuka')->dateTime('d M Y H.i', $tz),
                TextEntry::make('closed_at')->label('Ditutup')->dateTime('d M Y H.i', $tz)->placeholder('Masih terbuka'),
                TextEntry::make('opening_cash')->label('Modal awal')->formatStateUsing($money),
                TextEntry::make('expected_cash')->label('Kas seharusnya')->formatStateUsing($money)->placeholder('-'),
                TextEntry::make('counted_cash')->label('Kas dihitung')->formatStateUsing($money)->placeholder('-'),
                TextEntry::make('cash_variance')->label('Selisih')->formatStateUsing($money)->placeholder('-'),
                TextEntry::make('variance_note')->label('Keterangan selisih')->placeholder('-')->columnSpan(2),
            ]),
            ViewEntry::make('report')->view('filament.infolists.shift-report')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
            'view' => Pages\ViewShift::route('/{record}'),
        ];
    }
}
