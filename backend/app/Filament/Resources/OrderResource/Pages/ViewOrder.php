<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Modules\Sales\Domain\Models\Order;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return 'Transaksi '.($record instanceof Order ? $record->receipt_no : '');
    }

    protected function resolveRecord(int|string $key): Order
    {
        $record = parent::resolveRecord($key);
        abort_unless($record instanceof Order, 404);

        return $record->load(['items', 'payments', 'discounts', 'refunds', 'outlet', 'cashier']);
    }
}
