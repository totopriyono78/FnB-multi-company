<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Application\StockCountService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = InventoryFields::user();
        abort_if($user === null, 403);

        return MenuFields::run(fn () => app(StockCountService::class)->start($user, [
            'location_id' => (string) $data['location_id'],
            'scope' => (string) $data['scope'],
            'ingredient_ids' => array_values(array_map('strval', $data['ingredient_ids'] ?? [])),
            'notes' => $data['notes'] ?? null,
        ]));
    }

    public function getTitle(): string
    {
        return 'Mulai Stock Opname';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Mulai & Bekukan Saldo');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
