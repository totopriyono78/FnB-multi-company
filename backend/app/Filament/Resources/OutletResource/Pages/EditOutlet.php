<?php

namespace App\Filament\Resources\OutletResource\Pages;

use App\Filament\Resources\OutletResource;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditOutlet extends EditRecord
{
    protected static string $resource = OutletResource::class;

    protected function beforeSave(): void
    {
        /** @var Outlet $outlet */
        $outlet = $this->record;
        $brandId = $this->data['brand_id'] ?? null;
        if ($brandId !== $outlet->brand_id) {
            $brand = Brand::query()->find($brandId);
            if ($brand === null || ! (auth()->user()?->can('create', [Outlet::class, $brand]) ?? false)) {
                Notification::make()->danger()->title('Anda tidak berwenang memindahkan outlet ke brand ini.')->send();
                throw new Halt;
            }
        }
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
