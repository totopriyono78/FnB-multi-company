<?php

namespace App\Filament\Resources\PaymentGatewayAccountResource\Pages;

use App\Filament\Resources\PaymentGatewayAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGatewayAccount extends EditRecord
{
    protected static string $resource = PaymentGatewayAccountResource::class;

    /** Kunci tersimpan tidak pernah dikirim ke peramban; kolomnya selalu dibuka kosong. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['secret_key'] = null;

        return $data;
    }

    protected function afterSave(): void
    {
        PaymentGatewayAccountResource::audit('payment.gateway_account_updated', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
