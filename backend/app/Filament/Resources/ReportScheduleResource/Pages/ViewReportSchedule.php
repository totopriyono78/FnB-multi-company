<?php

namespace App\Filament\Resources\ReportScheduleResource\Pages;

use App\Filament\Resources\ReportScheduleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewReportSchedule extends ViewRecord
{
    protected static string $resource = ReportScheduleResource::class;

    public function getTitle(): string
    {
        return 'Jadwal: '.$this->getRecord()->getAttribute('name');
    }

    public function booted(): void
    {
        $this->getRecord()->loadMissing('owner:id,name');
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Ubah')];
    }
}
