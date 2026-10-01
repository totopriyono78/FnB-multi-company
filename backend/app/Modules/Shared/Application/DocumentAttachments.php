<?php

namespace App\Modules\Shared\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\Models\DocumentAttachment;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bukti yang menyertai dokumen apa pun (DOC-01): jurnal, SPPK, advis bayar.
 *
 * Dua keputusan yang membentuk kelas ini:
 *
 * 1. **Melampirkan boleh kapan saja, termasuk setelah dokumennya final.** Bukti sering datang
 *    belakangan — bukti transfer terbit sore, faktur menyusul seminggu kemudian. Menolak lampiran
 *    pada dokumen yang sudah selesai hanya membuat orang menyimpan buktinya di tempat lain, yang
 *    persis keadaan yang hendak diganti modul ini.
 * 2. **Menghapus hanya selama dokumennya masih bisa diubah.** Setelah diajukan, membuang bukti
 *    sama saja dengan menghapus jejak — dan itu justru yang paling harus dijaga.
 */
class DocumentAttachments
{
    public function __construct(
        private readonly AttachmentStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Model  $owner  dokumen pemiliknya; dipakai untuk jejak audit
     */
    public function attach(string $ownerType, Model $owner, UploadedFile $file, User $actor): DocumentAttachment
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $ownerId = (string) $owner->getKey();

        $max = (int) config('fnb.attachments.max_per_document', 10);
        if ($this->countFor($ownerType, $ownerId) >= $max) {
            throw new RuntimeException("Satu dokumen paling banyak memuat {$max} lampiran.");
        }

        $kb = (int) ceil(((int) $file->getSize()) / 1024);
        if ($kb > $this->store->maxKb()) {
            throw new RuntimeException('Berkas melebihi batas '.number_format($this->store->maxKb() / 1024, 0, ',', '.').' MB.');
        }

        $path = $this->store->put($file, $companyId);

        return DB::transaction(function () use ($ownerType, $ownerId, $owner, $file, $actor, $companyId, $path): DocumentAttachment {
            $row = new DocumentAttachment;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'path' => $path,
                // Nama asli hanya disimpan di basis data, tidak dipakai sebagai nama berkas di disk.
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
            ])->save();

            $this->audit->log('document_attachment.added', $owner,
                new: ['owner_type' => $ownerType, 'file' => $row->original_name], userId: $actor->id);

            return $row;
        });
    }

    /**
     * @param  bool  $editable  apakah dokumen pemiliknya masih boleh diubah
     */
    public function detach(DocumentAttachment $attachment, User $actor, bool $editable): void
    {
        if (! $editable) {
            throw new RuntimeException('Lampiran hanya dapat dihapus selama dokumennya masih dapat diubah.');
        }

        $path = $attachment->path;
        $name = $attachment->original_name;
        $attachment->delete();
        // Berkasnya dibuang setelah barisnya hilang: baris tanpa berkas lebih mudah dijelaskan
        // daripada baris yang menunjuk berkas yang sudah tidak ada.
        $this->store->forget($path);

        $this->audit->log('document_attachment.removed', null,
            old: ['owner_type' => $attachment->owner_type, 'file' => $name], userId: $actor->id);
    }

    /** @return Collection<int, DocumentAttachment> */
    public function forOwner(string $ownerType, string $ownerId): Collection
    {
        return DocumentAttachment::query()
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)
            ->orderBy('created_at')->get();
    }

    public function countFor(string $ownerType, string $ownerId): int
    {
        return DocumentAttachment::query()
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->count();
    }
}
