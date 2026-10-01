<?php

namespace App\Modules\Documents\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Antrian verifikasi pusat (DOC-07).
 *
 * Satu layar yang menjawab "apa yang menunggu saya" dan "apa yang tertahan di sini". Keduanya perlu,
 * dan keduanya berbeda:
 *
 * - **Menunggu saya** menghilangkan alasan "saya tidak tahu ada yang harus saya tanda tangani".
 *   Isinya dihitung dari matriks batas wewenang: hanya dokumen yang tingkat berikutnya memang peran
 *   orang ini, yang bukan pengajuannya sendiri, dan yang belum pernah ia tandatangani.
 * - **Tertahan** adalah dokumen yang menunggu orang lain melebihi tenggat. Ia ditampilkan kepada
 *   semua yang berhak melihat, karena dokumen yang macet hampir selalu macet karena tidak ada yang
 *   merasa itu bagiannya — bukan karena ada yang menolak.
 *
 * Antrian ini juga memuat **jurnal yang menunggu diposting** dan **advis bayar yang jurnalnya belum
 * terposting**. Yang kedua itu penting dan mudah terlewat: uangnya sudah keluar dari bank, tetapi
 * belum terbukukan — selisih yang baru ketemu saat rekonsiliasi bank, berminggu-minggu kemudian.
 *
 * Kelas ini sengaja menghitung, bukan menampilkan: logikanya diuji tanpa layar, dan layar hanya
 * menyusun hasilnya.
 */
class VerificationQueue
{
    public function __construct(private readonly ApprovalMatrix $matrix) {}

    public function slaDays(): int
    {
        return max(1, (int) config('fnb.documents.sla_days', 3));
    }

    /**
     * SPPK yang tingkat berikutnya menunggu tanda tangan orang ini.
     *
     * @return list<array{record: PaymentRequest, level: int, role: string, age: int, late: bool}>
     */
    public function awaitingMySignature(User $user): array
    {
        $out = [];
        /** @var Collection<int, PaymentRequest> $kandidat */
        $kandidat = PaymentRequest::query()
            ->where('status', PaymentRequest::SUBMITTED)
            ->where('requested_by', '!=', $user->id)
            ->whereDoesntHave('approvals', fn ($q) => $q->where('approved_by', $user->id))
            ->with(['outlet:id,name', 'requester:id,name'])
            ->withCount('approvals')
            ->orderBy('submitted_at')
            ->get();

        foreach ($kandidat as $sppk) {
            $level = $sppk->nextLevel();
            if ($level === null) {
                continue;
            }
            try {
                $role = $this->matrix->roleForLevel(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of($sppk->amount), $level);
            } catch (DocumentException) {
                // Matriksnya tidak memuat nilai ini lagi. Dokumennya tidak hilang — ia muncul di
                // daftar "tertahan", tempat yang memang untuk dokumen yang tidak bisa maju sendiri.
                continue;
            }
            if (! $this->matrix->holdsRole($user, $role)) {
                continue;
            }
            $umur = $this->ageInDays($sppk->submitted_at);
            $out[] = ['record' => $sppk, 'level' => $level, 'role' => $role,
                'age' => $umur, 'late' => $umur > $this->slaDays()];
        }

        return $out;
    }

    /**
     * SPPK yang menunggu tanda tangan siapa pun melebihi tenggat.
     *
     * @return list<array{record: PaymentRequest, age: int}>
     */
    public function stalled(): array
    {
        $batas = CarbonImmutable::now()->subDays($this->slaDays());

        /** @var Collection<int, PaymentRequest> $rows */
        $rows = PaymentRequest::query()
            ->where('status', PaymentRequest::SUBMITTED)
            ->where('submitted_at', '<', $batas)
            ->with(['outlet:id,name', 'requester:id,name'])
            ->withCount('approvals')
            ->orderBy('submitted_at')
            ->get();

        return $rows->map(fn (PaymentRequest $r) => ['record' => $r, 'age' => $this->ageInDays($r->submitted_at)])->all();
    }

    /**
     * Jurnal yang sudah diajukan dan menunggu diposting orang lain.
     *
     * Jurnal yang diajukan orang ini sendiri dikeluarkan dari daftarnya: memperlihatkannya hanya
     * membuat orang menekan tombol yang pasti ditolak pemisahan tugas.
     *
     * @return list<array{record: Journal, age: int, late: bool}>
     */
    public function journalsAwaitingPosting(User $user): array
    {
        /** @var Collection<int, Journal> $rows */
        $rows = Journal::query()
            ->where('status', Journal::SUBMITTED)
            ->where('submitted_by', '!=', $user->id)
            ->with('submitter:id,name')
            ->withSum('lines as total', 'debit')
            ->orderBy('submitted_at')
            ->get();

        return $rows->map(function (Journal $j): array {
            $umur = $this->ageInDays($j->submitted_at);

            return ['record' => $j, 'age' => $umur, 'late' => $umur > $this->slaDays()];
        })->all();
    }

    /**
     * Advis bayar yang jurnalnya belum terposting — uang sudah keluar, belum terbukukan.
     *
     * @return list<array{record: PaymentAdvice, age: int, late: bool}>
     */
    public function advicesNotBooked(): array
    {
        /** @var Collection<int, PaymentAdvice> $rows */
        $rows = PaymentAdvice::query()
            ->whereHas('journal', fn ($q) => $q->where('status', '!=', Journal::POSTED))
            ->with(['journal:id,number,status', 'request:id,number,payee_name'])
            ->orderBy('paid_on')
            ->get();

        return $rows->map(function (PaymentAdvice $a): array {
            $umur = $this->ageInDays($a->paid_on);

            return ['record' => $a, 'age' => $umur, 'late' => $umur > $this->slaDays()];
        })->all();
    }

    /**
     * SPPK yang sudah disetujui lengkap tetapi belum dibayar penuh.
     *
     * @return list<array{record: PaymentRequest, age: int, late: bool}>
     */
    public function approvedUnpaid(): array
    {
        /** @var Collection<int, PaymentRequest> $rows */
        $rows = PaymentRequest::query()
            ->where('status', PaymentRequest::APPROVED)
            ->whereColumn('paid_amount', '<', 'amount')
            ->with(['outlet:id,name', 'requester:id,name'])
            ->orderByRaw('due_date ASC NULLS LAST')
            ->orderBy('approved_at')
            ->get();

        return $rows->map(function (PaymentRequest $r): array {
            // Umur dihitung dari jatuh temponya bila ada — sebuah pengajuan belum terlambat hanya
            // karena lama disetujui, ia terlambat karena tenggat bayarnya lewat.
            $acuan = $r->due_date ?? $r->approved_at;
            $umur = $this->ageInDays($acuan);

            return ['record' => $r, 'age' => $umur,
                'late' => $r->due_date !== null ? $umur > 0 : $umur > $this->slaDays()];
        })->all();
    }

    /** @return array{signature: int, stalled: int, journals: int, unbooked: int, unpaid: int} */
    public function counts(User $user): array
    {
        return [
            'signature' => count($this->awaitingMySignature($user)),
            'stalled' => count($this->stalled()),
            'journals' => count($this->journalsAwaitingPosting($user)),
            'unbooked' => count($this->advicesNotBooked()),
            'unpaid' => count($this->approvedUnpaid()),
        ];
    }

    private function ageInDays(?CarbonImmutable $since): int
    {
        if ($since === null) {
            return 0;
        }

        return max(0, (int) $since->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()));
    }
}
