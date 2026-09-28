<?php

namespace App\Filament\Resources\PaymentGatewayAccountResource\Pages;

use App\Filament\Resources\PaymentGatewayAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGatewayAccounts extends ListRecords
{
    protected static string $resource = PaymentGatewayAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah kredensial')];
    }
}
