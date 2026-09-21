<?php

namespace App\Filament\Resources\SalesChannelResource\Pages;

use App\Filament\Resources\SalesChannelResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesChannel extends CreateRecord
{
    protected static string $resource = SalesChannelResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Tambah Channel Penjualan';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Channel');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
