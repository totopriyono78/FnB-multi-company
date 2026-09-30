<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    /**
     * Lewat layanan, bukan langsung ke model: saldo normal ditetapkan dari jenis akun dan
     * perubahannya tercatat di audit log. Form yang menyimpan sendiri akan melewati keduanya.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ChartOfAccounts::class)->create($data);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Akun tidak dapat dibuat')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
