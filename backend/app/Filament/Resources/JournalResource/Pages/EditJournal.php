<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditJournal extends EditRecord
{
    protected static string $resource = JournalResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('Hapus draft')];
    }

    /**
     * Baris jurnal tidak ikut terisi sendiri: ia tabel terpisah, bukan kolom.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        $data['lines'] = $record instanceof Journal ? JournalResource::linesFor($record) : [];

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $record instanceof Journal) {
            throw new Halt;
        }
        try {
            return app(JournalService::class)->update($record, $data, $user);
        } catch (AccountingException $e) {
            Notification::make()->danger()->title('Jurnal belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
