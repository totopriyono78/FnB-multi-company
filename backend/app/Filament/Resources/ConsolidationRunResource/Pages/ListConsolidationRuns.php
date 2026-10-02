<?php

namespace App\Filament\Resources\ConsolidationRunResource\Pages;

use App\Filament\Resources\ConsolidationRunResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsolidationRuns extends ListRecords
{
    protected static string $resource = ConsolidationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Periode baru')];
    }
}
