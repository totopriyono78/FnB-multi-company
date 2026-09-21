<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGoodsReceipt extends ViewRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    /** Relasi dimuat ulang di setiap request Livewire (model dihidrasi ulang tanpa relasi). */
    public function booted(): void
    {
        $this->getRecord()->loadMissing(['lines.ingredient', 'supplier', 'purchaseOrder', 'location.outlet', 'receiver']);
    }

    public function getTitle(): string
    {
        return 'Penerimaan '.$this->getRecord()->getAttribute('number');
    }
}
