<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Application\StockDocumentService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(StockDocumentService::class)->send($user, [
            'from_location_id' => (string) $data['from_location_id'],
            'to_location_id' => (string) $data['to_location_id'],
            'notes' => $data['notes'] ?? null,
            'lines' => array_values(array_map(fn (array $l) => [
                'ingredient_id' => (string) $l['ingredient_id'],
                'qty' => (string) $l['qty'],
                'note' => $l['note'] ?? null,
            ], $data['lines'] ?? [])),
        ]));
    }

    public function getTitle(): string
    {
        return 'Kirim Stok';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Kirim Sekarang');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
