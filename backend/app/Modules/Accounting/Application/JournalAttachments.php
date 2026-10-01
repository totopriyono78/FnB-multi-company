<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalAttachment;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bukti yang menyertai jurnal (ACC-05).
 *
 * Dua keputusan yang membentuk kelas ini:
 *
 * 1. **Melampirkan boleh kapan saja, termasuk setelah diposting.** Bukti sering datang belakangan —
 *    bukti transfer baru terbit sore, faktur menyusul seminggu kemudian. Menolak lampiran pada
 *    jurnal terposting hanya akan membuat orang menyimpan buktinya di tempat lain, yang persis
 *    keadaan yang hendak diganti modul ini. Yang tidak boleh berubah adalah ANGKANYA, bukan
 *    kelengkapan buktinya.
 * 2. **Menghapus hanya selama draft.** Setelah jurnal diajukan, membuang bukti sama saja dengan
 *    menghapus jejak — dan itu justru yang harus paling dijaga.
 */
class JournalAttachments
{
    public function __construct(
        private readonly AttachmentStore $store,
        private readonly AuditLogger $audit,
    ) {}

    public function attach(Journal $journal, UploadedFile $file, User $actor): JournalAttachment
    {
        $companyId = app(TenantContext::class)->requireCompanyId();

        $max = (int) config('fnb.attachments.max_per_document', 10);
        if (JournalAttachment::query()->where('journal_id', $journal->id)->count() >= $max) {
            throw new AccountingException('ATTACHMENT_LIMIT',
                "Satu jurnal paling banyak memuat {$max} lampiran.", 422, field: 'attachments');
        }

        $kb = (int) ceil($file->getSize() / 1024);
        if ($kb > $this->store->maxKb()) {
            throw new AccountingException('ATTACHMENT_TOO_LARGE',
                'Berkas melebihi batas '.number_format($this->store->maxKb() / 1024, 0, ',', '.').' MB.',
                422, field: 'attachments');
        }

        try {
            $path = $this->store->put($file, $companyId);
        } catch (\InvalidArgumentException $e) {
            throw new AccountingException('ATTACHMENT_TYPE', $e->getMessage(), 422, field: 'attachments');
        }

        return DB::transaction(function () use ($journal, $file, $actor, $companyId, $path): JournalAttachment {
            $row = new JournalAttachment;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'journal_id' => $journal->id,
                'path' => $path,
                // Nama asli hanya disimpan di basis data, tidak dipakai sebagai nama berkas di disk.
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
            ])->save();

            $this->audit->log('journal_attachment.added', $journal,
                new: ['number' => $journal->number, 'file' => $row->original_name], userId: $actor->id);

            return $row;
        });
    }

    public function detach(JournalAttachment $attachment, User $actor): void
    {
        /** @var Journal $journal */
        $journal = $attachment->journal()->firstOrFail();
        if (! $journal->isDraft()) {
            throw new AccountingException('ATTACHMENT_LOCKED',
                'Lampiran hanya dapat dihapus selama jurnalnya masih draft.', 409, field: 'attachments');
        }

        $path = $attachment->path;
        $name = $attachment->original_name;
        $attachment->delete();
        // Berkasnya dibuang setelah barisnya hilang: baris tanpa berkas lebih mudah dijelaskan
        // daripada baris yang menunjuk berkas yang sudah tidak ada.
        $this->store->forget($path);

        $this->audit->log('journal_attachment.removed', $journal,
            old: ['number' => $journal->number, 'file' => $name], userId: $actor->id);
    }
}
