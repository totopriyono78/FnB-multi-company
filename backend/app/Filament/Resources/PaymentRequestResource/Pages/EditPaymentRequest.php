<?php

namespace App\Filament\Resources\PaymentRequestResource\Pages;

use App\Filament\Resources\PaymentRequestResource;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\PayableService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\PaymentRequestInvoice;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditPaymentRequest extends EditRecord
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus draft')];
    }

    /**
     * Faktur yang ditunjuk tidak ikut terisi sendiri: ia tabel terpisah, bukan kolom.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['invoice_ids'] = $record instanceof PaymentRequest
            ? PaymentRequestInvoice::query()->where('payment_request_id', $record->id)
                ->pluck('purchase_invoice_id')->all()
            : [];

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record instanceof PaymentRequest) {
            throw new Halt;
        }
        try {
            $updated = app(PaymentRequestService::class)->update($record, $data, $user);
            $this->attachInvoices($updated, $data);

            return $updated;
        } catch (DocumentException|TreasuryException $e) {
            Notification::make()->danger()->title('Pengajuan belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    /**
     * Simpan faktur yang ditunjuk pengajuan ini.
     *
     * Tiap faktur dialokasikan **seluruh sisa hutangnya**. Pembayaran yang lebih kecil tetap bisa
     * dilakukan lewat advis bayar yang nilainya lebih kecil; alokasinya kemudian mengikuti jatuh
     * tempo terdekat. Meminta orang menentukan porsi per faktur di sini hanya menambah isian yang
     * hampir selalu diisi dengan nilai penuh.
     *
     * @param  array<string, mixed>  $data
     */
    private function attachInvoices(PaymentRequest $request, array $data): void
    {
        $ids = array_values(array_filter((array) ($data['invoice_ids'] ?? [])));
        if ($ids === []) {
            app(PayableService::class)->attachInvoices($request, []);

            return;
        }

        $alokasi = [];
        foreach (PurchaseInvoice::query()->whereKey($ids)->get() as $invoice) {
            $alokasi[$invoice->id] = (string) $invoice->outstanding()->toScale(2);
        }
        app(PayableService::class)->attachInvoices($request, $alokasi);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
