<?php

namespace App\Filament\Resources\ItemResource\RelationManagers;

use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Identity\Application\AccessScope;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Riwayat perubahan harga, hanya-baca (FR-MENU-15). */
class PriceHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistories';

    protected static ?string $title = 'Riwayat Harga';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function visibleOutlets(Builder $query): Builder
    {
        $user = MenuFields::user();
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if (app(AccessScope::class)->isCompanyWide($user)) {
            return $query;
        }
        $owner = $this->getOwnerRecord();
        $brandId = $owner instanceof Item ? $owner->brand_id : null;

        return $query->where(fn (Builder $q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', array_keys(MenuFields::outlets($brandId))));
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->visibleOutlets($query)->with(['variant', 'outlet', 'channel', 'changer']))
            ->columns([
                TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i', 'Asia/Jakarta')->sortable(),
                TextColumn::make('scope')->label('Berlaku untuk')
                    ->state(fn ($record) => collect([
                        $record->variant?->name,
                        $record->outlet->name ?? ($record->outlet_id ? 'Outlet' : null),
                        $record->channel?->name,
                    ])->filter()->implode(' · ') ?: 'Harga dasar'),
                TextColumn::make('old_price')->label('Harga lama')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah($state))->placeholder('-'),
                TextColumn::make('new_price')->label('Harga baru')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah($state))->placeholder('Dihapus'),
                TextColumn::make('changer.name')->label('Oleh')->placeholder('Sistem'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
