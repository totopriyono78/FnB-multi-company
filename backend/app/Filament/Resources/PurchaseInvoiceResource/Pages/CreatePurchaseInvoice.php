<?php

namespace App\Filament\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\Resources\PurchaseInvoiceResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\TreasuryException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseInvoice extends CreateRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    /**
     * Disimpan lewat PurchaseInvoiceService: di situlah nomor dokumen dibuat, jatuh tempo dihitung
     * dari termin supplier, dan aturan faktur pajak dijaga.
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
            return app(PurchaseInvoiceService::class)->create($data, $user);
        } catch (TreasuryException $e) {
            Notification::make()->danger()->title('Faktur belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
