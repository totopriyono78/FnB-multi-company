<?php

namespace App\Filament\Resources\RecurringJournalResource\Pages;

use App\Filament\Resources\RecurringJournalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecurringJournals extends ListRecords
{
    protected static string $resource = RecurringJournalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Templat baru')];
    }
}
