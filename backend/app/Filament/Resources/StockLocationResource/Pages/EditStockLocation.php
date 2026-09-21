<?php

namespace App\Filament\Resources\StockLocationResource\Pages;

use App\Filament\Resources\StockLocationResource;
use App\Filament\Support\MenuFields;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\StockLocation;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditStockLocation extends EditRecord
{
    protected static string $resource = StockLocationResource::class;

    public function getTitle(): string
    {
        return 'Ubah Lokasi Stok';
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var StockLocation $record */
        unset($data['outlet_id']);

        return MenuFields::run(fn () => app(StockLocations::class)->save($record, $data));
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
