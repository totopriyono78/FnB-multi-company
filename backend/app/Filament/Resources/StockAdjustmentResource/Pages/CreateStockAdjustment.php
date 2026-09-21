<?php

namespace App\Filament\Resources\StockAdjustmentResource\Pages;

use App\Filament\Resources\StockAdjustmentResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Application\StockDocumentService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(StockDocumentService::class)->adjust($user, [
            'location_id' => (string) $data['location_id'],
            'type' => (string) $data['type'],
            'reason_code' => (string) $data['reason_code'],
            'notes' => $data['notes'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->toIso8601String() : null,
            'lines' => array_values(array_map(fn (array $l) => [
                'ingredient_id' => (string) $l['ingredient_id'],
                'qty' => (string) $l['qty'],
                'unit_cost' => ($l['unit_cost'] ?? '') === '' ? null : (string) $l['unit_cost'],
                'note' => $l['note'] ?? null,
            ], $data['lines'] ?? [])),
        ]));
    }

    public function getTitle(): string
    {
        return 'Catat Penyesuaian / Waste';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Dokumen');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
