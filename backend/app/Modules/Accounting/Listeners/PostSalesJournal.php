<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\SalesJournal;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Events\BusinessDayClosed;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Menyusun jurnal penjualan begitu hari bisnis ditutup (ACC-11).
 *
 * **Kegagalan di sini tidak boleh menggagalkan Tutup Hari.** Kasir yang hendak pulang tidak punya
 * cara memperbaiki pemetaan akun, dan menahan penutupan hari karena urusan pembukuan berarti
 * menahan orang di toko atas sesuatu yang bisa diselesaikan besok pagi oleh finance. Karena itu
 * seluruh pekerjaan di kelas ini dijalankan setelah respons terkirim dan setiap kesalahan berhenti
 * di sini — tercatat di jejak audit, bukan dilemparkan ke layar POS.
 *
 * Yang hilang karena kegagalan itu tidak dibiarkan hilang: `akuntansi:jurnal-penjualan` menyusun
 * ulang hari mana pun yang sudah ditutup tetapi belum punya jurnal, termasuk hari-hari yang ditutup
 * sebelum modul ini ada.
 */
class PostSalesJournal
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SalesJournal $journal,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(BusinessDayClosed $event): void
    {
        $key = $event->companyId.':'.$event->outletId.':'.$event->businessDate;
        $this->later($key, fn () => $this->post($event->companyId, $event->outletId, $event->businessDate));
    }

    /**
     * Susun jurnal satu outlet-hari. Aman dipanggil berulang: hari yang sudah punya jurnal dilewati.
     *
     * @return bool true bila jurnal baru terbentuk
     */
    public function post(string $companyId, string $outletId, string $businessDate): bool
    {
        $work = fn (): bool => $this->build($outletId, $businessDate);

        return (bool) ($this->context->companyId() === $companyId
            ? $work()
            : $this->context->runAsTenant($companyId, $work));
    }

    private function build(string $outletId, string $businessDate): bool
    {
        /** @var Outlet|null $outlet */
        $outlet = Outlet::query()->find($outletId);
        if ($outlet === null) {
            return false;
        }
        $date = CarbonImmutable::parse($businessDate);

        $actor = $this->closer($outletId, $businessDate);
        if ($actor === null) {
            // Tanpa pelaku yang sah jurnalnya tidak bisa dipertanggungjawabkan kepada siapa pun.
            $this->note('journal.sales_skipped', $outlet, $date, 'CLOSER_UNKNOWN',
                'Penutup hari bisnis tidak diketahui sehingga jurnal tidak dapat dibuat atas nama siapa pun.');

            return false;
        }

        try {
            return DB::transaction(function () use ($outlet, $date, $actor): bool {
                // Tutup Hari dan perintah penyusun ulang bisa berjalan bersamaan.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acc-sales:'.$outlet->id.':'.$date->format('Y-m-d')]);
                if ($this->journal->existing($outlet, $date) !== null) {
                    return false;
                }

                return $this->journal->build($outlet, $date, $actor) !== null;
            });
        } catch (AccountingException $e) {
            /*
             * Pembukuan yang belum siap (bagan akun belum dipasang, pemetaan belum lengkap, periode
             * sudah dikunci) bukan kerusakan: ia keadaan yang wajar di entitas yang baru mulai.
             * Dicatat supaya terlihat, tidak dilaporkan sebagai galat supaya tidak menenggelamkan
             * galat yang sungguhan.
             */
            $this->note('journal.sales_skipped', $outlet, $date, $e->errorCode, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->note('journal.sales_failed', $outlet, $date, 'UNEXPECTED', mb_substr($e->getMessage(), 0, 300));
        }

        return false;
    }

    private function closer(string $outletId, string $businessDate): ?User
    {
        $closedBy = DB::table('business_days')
            ->where('outlet_id', $outletId)
            ->where('business_date', $businessDate)
            ->value('closed_by');

        return $closedBy === null ? null : User::query()->find((string) $closedBy);
    }

    private function note(string $action, Outlet $outlet, CarbonImmutable $date, string $code, string $message): void
    {
        $this->audit->log($action, $outlet, new: [
            'business_date' => $date->format('Y-m-d'),
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function later(string $key, Closure $work): void
    {
        if (app()->runningInConsole()) {
            $work();

            return;
        }
        // `always` agar tetap dijalankan walau respons Tutup Hari berakhir dengan galat lain.
        defer($work, 'acc-sales:'.$key)->always();
    }
}
