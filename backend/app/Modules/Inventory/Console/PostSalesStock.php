<?php

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Application\SalesStockPoster;
use App\Modules\Sales\Application\SalesStockFeed;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Jaring pengaman pemotongan stok: mengulang event yang gagal dan memproses transaksi yang terlewat
 * (mis. proses berhenti tepat setelah transaksi tersimpan). Dijadwalkan setiap 5 menit.
 */
class PostSalesStock extends Command
{
    protected $signature = 'inventory:post-sales {--hours=48 : Periksa transaksi yang diterima dalam rentang jam ini}';

    protected $description = 'Ulangi pemotongan stok penjualan yang gagal atau terlewat';

    public function handle(TenantContext $context, SalesStockPoster $poster, SalesStockFeed $feed): int
    {
        $since = CarbonImmutable::now()->subHours(max(1, (int) $this->option('hours')));
        $companies = $context->runAsSystem(fn () => Company::query()->pluck('id')->all());
        $processed = 0;

        foreach ($companies as $companyId) {
            $context->runAsTenant((string) $companyId, function () use ($poster, $feed, $since, $companyId, &$processed): void {
                $done = DB::table('stock_event_postings')->where('company_id', $companyId)
                    ->where(fn ($q) => $q->where('status', 'posted')->orWhere('attempts', '>=', SalesStockPoster::MAX_ATTEMPTS))
                    ->where('updated_at', '>=', $since->subDay())
                    ->pluck('key')->flip();

                foreach ($feed->ticketsSince($since) as $t) {
                    if (! $done->has('kitchen:'.$t['id'])) {
                        $poster->kitchen((string) $companyId, $t['id'], $t['order_id']);
                        $processed++;
                    }
                }
                foreach ($feed->ordersSince($since) as $o) {
                    if (! $done->has('completed:'.$o['id'])) {
                        $poster->completed((string) $companyId, $o['id']);
                        $processed++;
                    }
                    if ($o['voided_after_payment'] && ! $done->has('voided:'.$o['id'])) {
                        $poster->voided((string) $companyId, $o['id']);
                        $processed++;
                    }
                }
                foreach ($feed->refundsSince($since) as $r) {
                    if (! $done->has('refund:'.$r['id'])) {
                        $poster->refunded((string) $companyId, $r['id'], $r['order_id']);
                        $processed++;
                    }
                }
            });
        }

        $this->info("Event stok diproses: {$processed}");

        return self::SUCCESS;
    }
}
