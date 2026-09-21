<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string
    {
        return 'Ubah Draf '.$this->getRecord()->getAttribute('number');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->getRecord();
        $data['expected_date'] = $po->expected_date?->format('Y-m-d');
        $data['lines'] = $po->lines()->get()->mapWithKeys(fn ($l) => [(string) Str::uuid() => [
            'ingredient_id' => $l->ingredient_id,
            'unit_name' => $l->unit_name,
            'qty' => InventoryFields::plain((string) $l->qty),
            'unit_price' => MenuFields::plain((string) $l->unit_price),
        ]])->all();

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PurchaseOrder $record */
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(PurchaseOrderService::class)->update($user, $record, [
            'supplier_id' => (string) $data['supplier_id'],
            'expected_date' => $data['expected_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lines' => self::lines($data),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{ingredient_id: string, unit_name: string, qty: string, unit_price: string}>
     */
    public static function lines(array $data): array
    {
        return array_values(array_map(fn (array $l) => [
            'ingredient_id' => (string) $l['ingredient_id'],
            'unit_name' => (string) $l['unit_name'],
            'qty' => (string) $l['qty'],
            'unit_price' => (string) $l['unit_price'],
        ], $data['lines'] ?? []));
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Draf');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
