<?php

namespace App\Filament\Resources\RecurringJournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Filament\Resources\RecurringJournalResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRecurringJournal extends EditRecord
{
    protected static string $resource = RecurringJournalResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        [$debit, $credit] = JournalResource::sides($data['lines'] ?? []);
        if (! $debit->isEqualTo($credit) || $debit->isZero()) {
            Notification::make()->danger()->title('Templat belum seimbang')
                ->body('Debit dan kredit harus sama dan lebih dari nol sebelum templat disimpan.')->send();
            throw new Halt;
        }

        $record->forceFill([
            'name' => $data['name'],
            'description' => $data['description'],
            'day_of_month' => (int) $data['day_of_month'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'lines' => array_values($data['lines'] ?? []),
        ])->save();

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
