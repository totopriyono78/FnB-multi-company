<?php

namespace App\Filament\Resources\CashAccountResource\Pages;

use App\Filament\Resources\CashAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashAccounts extends ListRecords
{
    protected static string $resource = CashAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Rekening baru')];
    }
}
