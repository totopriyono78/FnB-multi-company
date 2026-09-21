<?php

namespace App\Filament\Resources\ReportScheduleResource\Pages;

use App\Filament\Resources\ReportScheduleResource;
use App\Filament\Support\ReportPage;
use App\Modules\Reporting\Application\ReportScheduler;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReportSchedule extends EditRecord
{
    protected static string $resource = ReportScheduleResource::class;

    public function getTitle(): string
    {
        return 'Ubah Jadwal Laporan';
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = ReportPage::user();
        abort_if($user === null || ! $record instanceof ReportSchedule, 403);

        return app(ReportScheduler::class)->save($user, $data + ['is_active' => $record->is_active], $record);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Jadwal laporan diperbarui.';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
