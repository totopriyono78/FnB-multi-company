<?php

namespace App\Filament\Resources\GoodsReceiptResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrderResource\Pages\EditPurchaseOrder;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Purchasing\Application\GoodsReceiptService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(GoodsReceiptService::class)->manual($user, [
            'location_id' => (string) $data['location_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'notes' => $data['notes'] ?? null,
            'received_at' => isset($data['received_at']) ? CarbonImmutable::parse($data['received_at'])->toIso8601String() : null,
            'lines' => EditPurchaseOrder::lines($data),
        ]));
    }

    public function getTitle(): string
    {
        return 'Terima Barang Tanpa PO';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Penerimaan');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
