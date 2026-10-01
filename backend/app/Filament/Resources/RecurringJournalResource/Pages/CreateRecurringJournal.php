<?php

namespace App\Filament\Resources\RecurringJournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Filament\Resources\RecurringJournalResource;
use App\Modules\Accounting\Domain\Models\RecurringJournal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateRecurringJournal extends CreateRecord
{
    protected static string $resource = RecurringJournalResource::class;

    /**
     * Keseimbangan diperiksa di sini, bukan hanya saat jurnalnya dibuat nanti.
     *
     * Templat yang timpang akan gagal diam-diam tiap bulan di perintah terjadwal — tercatat di
     * audit log yang jarang dibaca siapa pun. Jauh lebih murah menolaknya sekarang, di depan orang
     * yang sedang mengetiknya.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new Halt;
        }
        [$debit, $credit] = JournalResource::sides($data['lines'] ?? []);
        if (! $debit->isEqualTo($credit) || $debit->isZero()) {
            Notification::make()->danger()->title('Templat belum seimbang')
                ->body('Debit dan kredit harus sama dan lebih dari nol sebelum templat disimpan.')->send();
            throw new Halt;
        }

        $row = new RecurringJournal;
        $row->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => app(TenantContext::class)->requireCompanyId(),
            'name' => $data['name'],
            'description' => $data['description'],
            'day_of_month' => (int) $data['day_of_month'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'lines' => array_values($data['lines'] ?? []),
            'created_by' => $user->id,
        ])->save();

        return $row;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
