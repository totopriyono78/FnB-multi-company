<?php

namespace App\Filament\Resources\StockLocationResource\Pages;

use App\Filament\Resources\StockLocationResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateStockLocation extends CreateRecord
{
    protected static string $resource = StockLocationResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return MenuFields::run(function () use ($data): StockLocation {
            $outlet = Outlet::query()->findOrFail($data['outlet_id']);
            $user = InventoryFields::user();
            if ($user === null || ! InventoryFields::access()->canManageOutlet($user, $outlet)) {
                throw new AuthorizationException('Anda tidak memiliki izin mengelola lokasi stok outlet ini.');
            }

            return app(StockLocations::class)->save(new StockLocation(['outlet_id' => $outlet->id]), $data);
        });
    }

    public function getTitle(): string
    {
        return 'Tambah Lokasi Stok';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Lokasi');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
