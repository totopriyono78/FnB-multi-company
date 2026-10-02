<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Filament\Resources\CashTransactionResource;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\CashTransactionService;
use App\Modules\Treasury\Application\TreasuryException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateCashTransaction extends CreateRecord
{
    protected static string $resource = CashTransactionResource::class;

    /**
     * Disimpan lewat CashTransactionService: di situlah jurnalnya dibuat, periode diperiksa, dan
     * bentuk barisnya dijaga. Form yang menyimpan sendiri akan melewati semuanya sekaligus.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new Halt;
        }
        try {
            return app(CashTransactionService::class)->record($data, $user);
        } catch (TreasuryException|AccountingException $e) {
            // AccountingException ikut ditangkap karena jurnalnya dibuat di sini: periode yang sudah
            // ditutup datang dari modul akuntansi, bukan dari aturan kas.
            Notification::make()->danger()->title('Mutasi belum dapat dicatat')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
