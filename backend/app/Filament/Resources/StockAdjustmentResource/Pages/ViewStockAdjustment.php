<?php

namespace App\Filament\Resources\StockAdjustmentResource\Pages;

use App\Filament\Resources\StockAdjustmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStockAdjustment extends ViewRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    /** Relasi dimuat ulang di setiap request Livewire (model dihidrasi ulang tanpa relasi). */
    public function booted(): void
    {
        $this->getRecord()->loadMissing(['lines.ingredient', 'location.outlet', 'author']);
    }

    public function getTitle(): string
    {
        return 'Dokumen '.$this->getRecord()->getAttribute('number');
    }
}
