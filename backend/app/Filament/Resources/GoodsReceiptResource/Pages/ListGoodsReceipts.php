<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGoodsReceipts extends ListRecords
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Terima Tanpa PO')];
    }
}
