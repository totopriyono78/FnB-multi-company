<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(PurchaseOrderService::class)->create($user, [
            'location_id' => (string) $data['location_id'],
            'supplier_id' => (string) $data['supplier_id'],
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lines' => EditPurchaseOrder::lines($data),
        ]));
    }

    public function getTitle(): string
    {
        return 'Buat Purchase Order';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Draf');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
