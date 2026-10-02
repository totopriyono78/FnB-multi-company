<?php

namespace App\Filament\Resources\ConsolidationRunResource\Pages;

use App\Filament\Resources\ConsolidationRunResource;
use App\Filament\Support\ConsolidationAccess;
use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\ConsolidationService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Disimpan lewat ConsolidationService: di situlah nomor dokumen dibuat dan aturan "satu proses per
 * periode" dijaga. Periode yang sudah ada tidak menghasilkan galat — ia mengembalikan proses yang
 * sudah ada, karena itulah yang dimaksud orang yang mengetik periode yang sama.
 */
class CreateConsolidationRun extends CreateRecord
{
    protected static string $resource = ConsolidationRunResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $group = ConsolidationAccess::group();
        if ($group === null) {
            Notification::make()->danger()->title('Entitas ini bukan entitas holding')
                ->body('Hanya entitas yang memegang grup yang bisa menjalankan konsolidasi.')->send();
            throw new Halt;
        }

        try {
            return app(ConsolidationService::class)->openRun(
                $group,
                CarbonImmutable::parse((string) $data['period_start'])->startOfDay(),
                CarbonImmutable::parse((string) $data['period_end'])->startOfDay(),
                isset($data['label']) ? (string) $data['label'] : null,
            );
        } catch (ConsolidationException $e) {
            Notification::make()->danger()->title('Periode belum dapat dibuat')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
