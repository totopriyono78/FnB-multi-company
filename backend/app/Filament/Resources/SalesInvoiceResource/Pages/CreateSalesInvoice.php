<?php

namespace App\Filament\Resources\SalesInvoiceResource\Pages;

use App\Filament\Resources\SalesInvoiceResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\TreasuryException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new Halt;
        }
        try {
            return app(ReceivableService::class)->create($data, $user);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Tagihan belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
