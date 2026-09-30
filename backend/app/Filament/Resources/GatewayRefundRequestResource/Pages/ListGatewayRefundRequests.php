<?php

namespace App\Filament\Resources\GatewayRefundRequestResource\Pages;

use App\Filament\Resources\GatewayRefundRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListGatewayRefundRequests extends ListRecords
{
    protected static string $resource = GatewayRefundRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Dana QRIS dan e-wallet tidak bisa ditarik dari kasir — gateway belum menyediakan API refund. '
            .'Kembalikan dananya lewat dashboard gateway lebih dulu, baru catat di sini.';
    }
}
