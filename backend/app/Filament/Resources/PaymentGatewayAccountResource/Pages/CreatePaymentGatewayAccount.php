<?php

namespace App\Filament\Resources\PaymentGatewayAccountResource\Pages;

use App\Filament\Resources\PaymentGatewayAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentGatewayAccount extends CreateRecord
{
    protected static string $resource = PaymentGatewayAccountResource::class;

    protected function afterCreate(): void
    {
        PaymentGatewayAccountResource::audit('payment.gateway_account_created', $this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
