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

    public function assertOpen(AccountingPeriod $period): void
    {
        if ($period->isClosed()) {
            throw new AccountingException(
                'PERIOD_CLOSED',
                'Periode '.$period->label().' sudah ditutup. Posting ke periode tertutup tidak diperbolehkan — buka kembali periodenya, atau catat di periode berjalan.',
                422, field: 'journal_date', details: ['period' => $period->label()],
            );
        }
    }

    public function close(AccountingPeriod $period, User $by, ?string $note = null): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $by, $note): AccountingPeriod {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            if ($locked->isClosed()) {
                throw new AccountingException('PERIOD_ALREADY_CLOSED', 'Periode ini sudah ditutup.', 409);
            }

            /*
             * Jurnal draft yang tertinggal adalah alasan paling sering sebuah periode "ditutup"
             * lalu harus dibuka lagi. Lebih baik ditolak sekarang, dengan jumlahnya disebutkan.
             */
            $draft = Journal::query()->where('period_id', $locked->id)->where('status', Journal::DRAFT)->count();
            if ($draft > 0) {
                throw new AccountingException(
                    'PERIOD_HAS_DRAFTS',
                    "Masih ada {$draft} jurnal draft di periode ini. Posting atau hapus dulu sebelum menutup periode.",
                    422, details: ['draft_count' => $draft],
                );
            }

            $locked->forceFill([
                'status' => AccountingPeriod::CLOSED,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'close_note' => $note === null ? null : mb_substr(trim($note), 0, 300),
            ])->save();

            $this->audit->log('accounting_period.closed', $locked, new: ['period' => $locked->label()], reason: $note, userId: $by->id);

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
            if (! $locked->isClosed()) {
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
