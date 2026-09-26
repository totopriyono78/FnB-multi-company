<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DeviceResource;
use App\Modules\Tenancy\Domain\DeviceStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Kesehatan sinkronisasi perangkat (NFR-OBS-04). */
class DeviceHealth extends TableWidget
{
    /** Dirender bersama halaman: satu request, bukan satu request per widget (lebih ringan di server satu proses). */
    protected static bool $isLazy = false;

    protected static ?string $heading = 'Perangkat yang perlu dicek';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $cutoff = now()->subSeconds((int) config('fnb.devices.offline_after_seconds'));

        return $table
            ->query(fn () => DeviceResource::getEloquentQuery()
                ->with('outlet')
                ->where('status', DeviceStatus::Active->value)
                ->where(fn ($q) => $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $cutoff)
                    ->orWhere('pending_sync_count', '>', 0)))
            ->columns([
                TextColumn::make('outlet.name')->label('Outlet'),
                TextColumn::make('code')->label('Perangkat'),
                TextColumn::make('last_seen_at')->label('Terakhir online')->since()->placeholder('Belum pernah'),
                TextColumn::make('pending_sync_count')->label('Belum sinkron')->numeric()->alignEnd(),
            ])
            ->defaultSort('last_seen_at')
            ->paginated([5, 10])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Semua perangkat online dan tersinkron');
    }
}
