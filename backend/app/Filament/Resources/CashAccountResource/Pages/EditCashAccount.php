<?php

namespace App\Filament\Resources\CashAccountResource\Pages;

use App\Filament\Resources\CashAccountResource;
use App\Modules\Treasury\Application\CashAccountService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\CashAccount;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCashAccount extends EditRecord
{
    protected static string $resource = CashAccountResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof CashAccount) {
            throw new Halt;
        }
        try {
            return app(CashAccountService::class)->update($record, $data);
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
