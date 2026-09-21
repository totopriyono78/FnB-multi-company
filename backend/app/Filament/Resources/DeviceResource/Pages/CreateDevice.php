<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use App\Modules\Tenancy\Application\DevicePairingService;
use App\Modules\Tenancy\Application\PlanLimits;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    protected static bool $canCreateAnother = false;

    protected function beforeCreate(): void
    {
        $outlet = Outlet::query()->find($this->data['outlet_id'] ?? null);
        if ($outlet === null || ! auth()->user()?->can('create', [Device::class, $outlet])) {
            Notification::make()->danger()->title('Anda tidak berwenang menambah perangkat di outlet ini.')->send();
            $this->halt();
        }

        try {
            app(PlanLimits::class)->ensureCanAddDevice();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('Batas paket tercapai')->body($e->validator->errors()->first())->send();
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        /** @var Device $device */
        $device = $this->record;
        $pairing = app(DevicePairingService::class)->issueCode($device);

        Notification::make()
            ->success()
            ->title('Kode pairing: '.chunk_split($pairing['code'], 4, ' '))
            ->body('Masukkan kode ini di aplikasi kasir. Berlaku sampai '.$pairing['expires_at']->timezone(config('app.display_timezone'))->format('H.i').'.')
            ->persistent()
            ->send();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    public function getTitle(): string
    {
        return 'Daftarkan Perangkat';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Daftarkan & Buat Kode Pairing');
    }
}
