<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Modules\Accounting\Domain\Models\JournalAttachment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\TenantContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mengunduh bukti yang dilampirkan pada jurnal.
 *
 * Kebalikan dari `MediaController`: **berotentikasi dan diperiksa per entitas**. Foto nota memuat
 * nama pihak, nominal, kadang NPWP — nama berkas acak saja tidak cukup, karena tautan yang bocor
 * sekali akan berlaku selamanya.
 *
 * Tiga penjagaan berlapis, sengaja tidak mengandalkan satu pun di antaranya sendirian:
 * RLS menyaring baris menurut entitas aktif; jalur berkasnya sendiri memuat id company dan
 * dicocokkan ulang; dan izin `accounting.view` diperiksa terpisah.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentStore $store) {}

    public function show(string $attachment): Response|StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('accounting.view'), 403);

        /** @var JournalAttachment|null $row */
        $row = JournalAttachment::query()->find($attachment);
        abort_if($row === null, 404);

        /*
         * Jalur berkas memuat id company. Dicocokkan ulang dengan entitas aktif supaya baris yang
         * (karena bug apa pun) lolos dari RLS tetap tidak bisa menarik berkas milik entitas lain.
         */
        $companyId = app(TenantContext::class)->requireCompanyId();
        abort_unless($row->company_id === $companyId, 404);
        abort_unless($this->store->ownerCompanyId($row->path) === $companyId, 404);

        $disk = $this->store->disk();
        abort_unless($disk->exists($row->path), 404);

        return $disk->response($row->path, $row->original_name, [
            'Cache-Control' => 'private, no-store',
            // Ditampilkan di tab, bukan dipaksa unduh: kebanyakan lampiran diperiksa sekilas.
            'Content-Disposition' => 'inline; filename="'.addslashes($row->original_name).'"',
        ]);
    }
}
