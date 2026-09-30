<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Identity\Domain\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateJournal extends CreateRecord
{
    protected static string $resource = JournalResource::class;

    /**
     * Disimpan lewat JournalService, bukan oleh form: di situlah keseimbangan diperiksa, periode
     * dikunci, nomor dokumen dibuat, dan audit log ditulis. Form yang menyimpan sendiri akan
     * melewati semuanya sekaligus.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new Halt;
        }
        try {
            return app(JournalService::class)->create($data, $user);
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
