<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Filament\Support\AccountingAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('installTemplate')
                ->label('Pasang template standar')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn () => AccountingAccess::canManage())
                ->requiresConfirmation()
                ->modalHeading('Pasang bagan akun standar F&B')
                ->modalDescription('Akun yang kodenya sudah ada tidak akan diubah atau diduplikasi — aman dijalankan berulang, misalnya setelah template bertambah.')
                ->modalSubmitActionLabel('Pasang')
                ->action(fn () => AccountResource::installTemplate()),
            CreateAction::make()->label('Akun baru'),
        ];
    }
}
