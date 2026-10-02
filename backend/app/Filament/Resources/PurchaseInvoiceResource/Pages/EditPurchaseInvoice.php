<?php

namespace App\Filament\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\Resources\PurchaseInvoiceResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\PurchaseInvoiceService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseInvoice extends EditRecord
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus draft')];
    }

    /**
     * Baris faktur tidak ikut terisi sendiri: ia tabel terpisah, bukan kolom.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['lines'] = $record instanceof PurchaseInvoice ? PurchaseInvoiceResource::linesFor($record) : [];

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record instanceof PurchaseInvoice) {
            throw new Halt;
        }
        try {
            return app(PurchaseInvoiceService::class)->update($record, $data, $user);
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
