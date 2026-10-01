<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\AccountingPeriod;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Periode akuntansi bulanan (ACC-04).
 *
 * Periodenya dibuat saat pertama kali dibutuhkan, bukan disemai setahun penuh di muka: entitas baru
 * tidak perlu 12 baris kosong, dan tahun depan tidak perlu diingat siapa pun.
 */
class PeriodService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Periode yang memuat tanggal tersebut; dibuat bila belum ada. */
    public function forDate(CarbonImmutable $date): AccountingPeriod
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');

        $period = AccountingPeriod::query()->where('year', $year)->where('month', $month)->first();
        if ($period !== null) {
            return $period;
        }

        /*
         * Dua permintaan bersamaan bisa sama-sama menemukan "belum ada". Indeks unik
         * (company_id, year, month) yang memutuskan; yang kalah membaca ulang barisnya.
         */
        try {
            $period = new AccountingPeriod;
            $period->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'year' => $year,
                'month' => $month,
                'starts_on' => $date->startOfMonth()->format('Y-m-d'),
                'ends_on' => $date->endOfMonth()->format('Y-m-d'),
                'status' => AccountingPeriod::OPEN,
            ])->save();

            return $period;
        } catch (UniqueConstraintViolationException) {
            return AccountingPeriod::query()->where('year', $year)->where('month', $month)->firstOrFail();
        }
    }

    /**
     * Boleh diposting? Periode terbuka jelas boleh; periode **soft close** juga boleh, tetapi
     * pencatatannya ditandai (ACC-04).
     *
     * Soft close menjawab keadaan yang selalu ada di praktik: laporan bulan lalu sudah terbit,
     * tetapi masih muncul satu-dua koreksi yang memang harus masuk ke bulan itu. Tanpa tingkat
     * antara ini, orang hanya punya dua pilihan yang sama-sama buruk — membuka kembali periode
     * (sehingga seluruh bulan kembali bebas disunting) atau memaksakan koreksi ke bulan berjalan
     * (sehingga bulan lalu tetap salah selamanya).
     */
    public function assertPostable(AccountingPeriod $period): void
    {
        if ($period->isHardClosed()) {
            throw new AccountingException(
                'PERIOD_CLOSED',
                'Periode '.$period->label().' sudah ditutup permanen. Posting ke periode tertutup tidak diperbolehkan — buka kembali periodenya, atau catat di periode berjalan.',
                422, field: 'journal_date', details: ['period' => $period->label()],
            );
        }
    }

    /** Masih dipakai tempat yang menuntut periode benar-benar terbuka (mis. tutup buku). */
    public function assertOpen(AccountingPeriod $period): void
    {
        if (! $period->isOpen()) {
            throw new AccountingException(
                'PERIOD_NOT_OPEN',
                'Periode '.$period->label().' tidak dalam keadaan terbuka.',
                422, field: 'journal_date', details: ['period' => $period->label()],
            );
        }
    }

    /**
     * @param  bool  $hard  true = tutup permanen (tidak ada posting sama sekali);
     *                      false = soft close, koreksi masih mungkin tetapi tercatat
     */
    public function close(AccountingPeriod $period, User $by, ?string $note = null, bool $hard = true): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $by, $note, $hard): AccountingPeriod {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->isHardClosed()) {
                throw new AccountingException('PERIOD_ALREADY_CLOSED', 'Periode ini sudah ditutup permanen.', 409);
            }
            if ($locked->isSoftClosed() && ! $hard) {
                throw new AccountingException('PERIOD_ALREADY_CLOSED', 'Periode ini sudah dalam soft close.', 409);
            }

            /*
             * Jurnal yang belum masuk buku besar — draft maupun yang masih diajukan — adalah alasan
             * paling sering sebuah periode "ditutup" lalu harus dibuka lagi. Lebih baik ditolak
             * sekarang, dengan jumlahnya disebutkan.
             */
            $tertunda = Journal::query()->where('period_id', $locked->id)
                ->whereIn('status', [Journal::DRAFT, Journal::SUBMITTED])->count();
            if ($tertunda > 0) {
                throw new AccountingException(
                    'PERIOD_HAS_DRAFTS',
                    "Masih ada {$tertunda} jurnal yang belum diposting di periode ini. Selesaikan dulu sebelum menutup periode.",
                    422, details: ['pending_count' => $tertunda],
                );
            }

            $locked->forceFill([
                'status' => $hard ? AccountingPeriod::CLOSED : AccountingPeriod::SOFT_CLOSED,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'close_note' => $note === null ? null : mb_substr(trim($note), 0, 300),
            ])->save();

            $this->audit->log($hard ? 'accounting_period.closed' : 'accounting_period.soft_closed', $locked,
                new: ['period' => $locked->label()], reason: $note, userId: $by->id);

            return $locked;
        });
    }

    /** Membuka kembali periode yang sudah ditutup — selalu tercatat, karena ini mengubah laporan yang sudah terbit. */
    public function reopen(AccountingPeriod $period, User $by, string $reason): AccountingPeriod
    {
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new AccountingException('REOPEN_REASON_REQUIRED', 'Alasan membuka kembali periode wajib diisi.', 422, field: 'reason');
        }

        return DB::transaction(function () use ($period, $by, $text): AccountingPeriod {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->isOpen()) {
                throw new AccountingException('PERIOD_NOT_CLOSED', 'Periode ini memang belum ditutup.', 409);
            }

            $locked->forceFill([
                'status' => AccountingPeriod::OPEN,
                'closed_at' => null,
                'closed_by' => null,
                'close_note' => mb_substr($text, 0, 300),
            ])->save();

            $this->audit->log('accounting_period.reopened', $locked, new: ['period' => $locked->label()], reason: $text, userId: $by->id);

            return $locked;
        });
    }
}
