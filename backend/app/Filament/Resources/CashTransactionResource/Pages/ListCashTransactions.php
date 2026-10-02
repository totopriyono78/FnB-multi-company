<?php

namespace App\Filament\Resources\CashTransactionResource\Pages;

use App\Filament\Resources\CashTransactionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashTransactions extends ListRecords
{
    protected static string $resource = CashTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Catat mutasi')];
    }
}
