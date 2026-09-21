<?php

namespace App\Filament\Resources\OutletResource\Pages;

use App\Filament\Resources\OutletResource;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

class CreateOutlet extends CreateRecord
{
    protected static string $resource = OutletResource::class;

    protected static bool $canCreateAnother = false;

    protected function beforeCreate(): void
    {
        $brand = Brand::query()->find($this->data['brand_id'] ?? null);
        if ($brand === null || ! (auth()->user()?->can('create', [Outlet::class, $brand]) ?? false)) {
            Notification::make()->danger()->title('Anda tidak berwenang menambah outlet untuk brand ini.')->send();
            throw new Halt;
        }

        try {
            app(PlanLimits::class)->ensureCanAddOutlet();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Batas paket tercapai')->body($e->validator->errors()->first())->send();
            $this->halt();
        }
    }

    public function getTitle(): string
    {
        return 'Tambah Outlet';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Outlet');
    }
}
