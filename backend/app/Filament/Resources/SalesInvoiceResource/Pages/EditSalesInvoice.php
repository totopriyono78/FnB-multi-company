<?php

namespace App\Filament\Resources\SalesInvoiceResource\Pages;

use App\Filament\Resources\SalesInvoiceResource;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\ReceivableService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus draft')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['lines'] = $record instanceof SalesInvoice ? SalesInvoiceResource::linesFor($record) : [];

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record instanceof SalesInvoice) {
            throw new Halt;
        }
        try {
            return app(ReceivableService::class)->update($record, $data, $user);
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
