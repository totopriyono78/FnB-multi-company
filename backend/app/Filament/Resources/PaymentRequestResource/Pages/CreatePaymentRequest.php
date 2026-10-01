<?php

namespace App\Filament\Resources\PaymentRequestResource\Pages;

use App\Filament\Resources\PaymentRequestResource;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Identity\Domain\Models\User;
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
            return app(PaymentRequestService::class)->create($data, $user);
        } catch (DocumentException $e) {
            Notification::make()->danger()->title('Pengajuan belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
