<?php

namespace App\Filament\Resources\PaymentRequestResource\Pages;

use App\Filament\Resources\PaymentRequestResource;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Treasury\Application\PayableService;
use App\Modules\Treasury\Application\TreasuryException;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreatePaymentRequest extends CreateRecord
{
    protected static string $resource = PaymentRequestResource::class;

    /**
     * Disimpan lewat PaymentRequestService: di situlah nomor dokumen dibuat, nama penerima disalin
     * dari master supplier, akun diperiksa dapat dijurnal, dan audit log ditulis.
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
            $request = app(PaymentRequestService::class)->create($data, $user);
            $this->attachInvoices($request, $data);

            return $request;
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
