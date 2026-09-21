<?php

namespace App\Filament\Resources\ShiftResource\Pages;

use App\Filament\Resources\ShiftResource;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Domain\Models\CashMovement;
use App\Modules\Sales\Domain\Models\Shift;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;

class ViewShift extends ViewRecord
{
    protected static string $resource = ShiftResource::class;

    public function getTitle(): string
    {
        return 'Detail Shift';
    }

    protected function resolveRecord(int|string $key): Shift
    {
        $record = parent::resolveRecord($key);
        abort_unless($record instanceof Shift, 404);

        return $record->load(['outlet', 'cashier', 'device']);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $shift = $this->getRecord();
        abort_unless($shift instanceof Shift, 404);

        return $shift->summary ?? app(ShiftReport::class)->build($shift);
    }

    /** @return Collection<int, CashMovement> */
    public function movements(): Collection
    {
        return CashMovement::query()->where('shift_id', $this->getRecord()->getKey())->orderBy('device_created_at')->get();
    }
}
