<?php

namespace App\Filament\Resources\ReportScheduleResource\Pages;

use App\Filament\Resources\ReportScheduleResource;
use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportScheduler;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReportSchedule extends CreateRecord
{
    protected static string $resource = ReportScheduleResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Buat Jadwal Laporan';
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $user = ReportPage::user();
        abort_if($user === null, 403);

        return app(ReportScheduler::class)->save($user, $data);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Jadwal');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Jadwal laporan disimpan.';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
