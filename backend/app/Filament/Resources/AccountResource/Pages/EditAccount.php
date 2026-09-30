<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(function (Account $record): void {
                try {
                    app(ChartOfAccounts::class)->delete($record);
                } catch (AccountingException $e) {
                    Notification::make()->danger()->title('Akun tidak dapat dihapus')->body($e->getMessage())->send();
                    throw new Halt;
                }
            }),
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Account) {
            throw new Halt;
        }
        try {
            return app(ChartOfAccounts::class)->update($record, $data);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Akun tidak dapat diubah')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
