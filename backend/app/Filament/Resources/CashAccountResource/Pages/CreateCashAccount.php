<?php

namespace App\Filament\Resources\CashAccountResource\Pages;

use App\Filament\Resources\CashAccountResource;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\TreasuryException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateCashAccount extends CreateRecord
{
    protected static string $resource = CashAccountResource::class;

    /**
     * Disimpan lewat CashAccountService: di situlah akun buku besar dibuatkan bila tidak dipilih,
     * dan di situlah aturan "satu akun hanya untuk satu rekening" dijaga.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CashAccountService::class)->create($data);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Rekening belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
