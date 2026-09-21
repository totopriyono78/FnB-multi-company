<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Sales\Application\SalesStockFeed;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Memotong & mengembalikan stok dari transaksi POS (FR-INV-04, ADR 0005).
 *
 * - Pemicu outlet `on_kitchen`: stok dipotong saat tiket dapur diterima; baris yang tidak pernah dikirim ke dapur
 *   dipotong saat pesanan lunas. Pesanan yang dibatalkan setelah dikirim ke dapur dicatat sebagai waste.
 * - Pemicu `on_payment`: stok dipotong saat pesanan lunas; pembatalan sebelum bayar tidak memotong stok.
 * - Void setelah bayar / refund "kembali ke stok" mengembalikan bahan dengan HPP saat dipotong.
 *
 * Setiap event dicatat di `stock_event_postings`; kegagalan tidak membatalkan transaksi penjualan dan
 * diulang oleh perintah `inventory:post-sales`.
 */
class SalesStockPoster
{
    public const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly TenantContext $context,
        private readonly SalesStockFeed $feed,
        private readonly RecipeExplorer $recipes,
        private readonly StockLedger $ledger,
        private readonly StockLocations $locations,
    ) {}

    public function completed(string $companyId, string $orderId): void
    {
        $this->run($companyId, 'completed:'.$orderId, 'completed', $orderId, $orderId, fn () => $this->postCompleted($orderId));
    }

    public function kitchen(string $companyId, string $ticketId, string $orderId): void
    {
        $this->run($companyId, 'kitchen:'.$ticketId, 'kitchen', $ticketId, $orderId, fn () => $this->postKitchen($ticketId));
    }

    public function voided(string $companyId, string $orderId): void
    {
        $this->run($companyId, 'voided:'.$orderId, 'voided', $orderId, $orderId, fn () => $this->postVoided($orderId));
    }

    public function refunded(string $companyId, string $refundId, string $orderId): void
    {
        $this->run($companyId, 'refund:'.$refundId, 'refund', $refundId, $orderId, fn () => $this->postRefund($refundId));
    }

    /** Status posting sebuah event (null = belum pernah diproses). */
    public function status(string $key): ?string
    {
        $value = DB::table('stock_event_postings')
            ->where('company_id', $this->context->requireCompanyId())
            ->where('key', $key)
            ->value('status');

        return $value === null ? null : (string) $value;
    }

    private function run(string $companyId, string $key, string $event, string $subjectId, string $orderId, Closure $work): void
    {
        $exec = function () use ($key, $event, $subjectId, $orderId, $work): void {
            if ($this->status($key) === 'posted') {
                return;
            }
            try {
                DB::transaction(function () use ($key, $event, $subjectId, $orderId, $work): void {
                    // Satu proses per event (listener & perintah ulang bisa berjalan bersamaan).
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['stock:'.$key]);
                    if ($this->status($key) === 'posted') {
                        return;
                    }
                    $work();
                    $this->mark($key, $event, $subjectId, $orderId, 'posted', null);
                });
            } catch (Throwable $e) {
                report($e);
                $this->mark($key, $event, $subjectId, $orderId, 'failed', mb_substr($e->getMessage(), 0, 300));
            }
        };

        if ($this->context->companyId() === $companyId) {
            $exec();
        } else {
            $this->context->runAsTenant($companyId, $exec);
        }
    }

    private function mark(string $key, string $event, string $subjectId, string $orderId, string $status, ?string $error): void
    {
        DB::statement(
            'INSERT INTO stock_event_postings (company_id, key, event, subject_id, order_id, status, attempts, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, now(), now())
             ON CONFLICT (company_id, key) DO UPDATE SET status = EXCLUDED.status,
                 attempts = stock_event_postings.attempts + EXCLUDED.attempts, last_error = EXCLUDED.last_error, updated_at = now()',
            [$this->context->requireCompanyId(), $key, $event, $subjectId, $orderId, $status, $status === 'failed' ? 1 : 0, $error],
        );
    }

    private function postCompleted(string $orderId): void
    {
        $order = $this->feed->order($orderId) ?? throw new InventoryException('ORDER_NOT_FOUND', 'Transaksi belum tersedia.');
        $outlet = $this->outlet($order['outlet_id']);
        $at = CarbonImmutable::parse($order['completed_at']);

        if ($order['was_paid']) {
            $this->postLines($outlet, $order['id'], $order['lines'], 'order', 'sale', $order['business_date'], $at, 'order', $order['id'], $order['receipt_no'], $order['cashier_id'], null);

            return;
        }

        // Dibatalkan sebelum bayar: hanya bahan yang sudah diolah dapur yang berkurang (sebagai waste).
        if ($outlet->stock_deduction_trigger === 'on_kitchen') {
            $sent = array_values(array_filter($order['lines'], fn (array $l) => $l['sent_to_kitchen_at'] !== null));
            $this->postLines($outlet, $order['id'], $sent, 'order', 'waste', $order['business_date'], $at, 'order', $order['id'], $order['receipt_no'], $order['voided_by'], 'Pesanan dibatalkan setelah dikirim ke dapur');
        }
    }

    private function postKitchen(string $ticketId): void
    {
        $ticket = $this->feed->ticket($ticketId) ?? throw new InventoryException('TICKET_NOT_FOUND', 'Tiket dapur belum tersedia.');
        $outlet = $this->outlet($ticket['outlet_id']);
        if ($outlet->stock_deduction_trigger !== 'on_kitchen') {
            return;
        }
        $this->postLines($outlet, $ticket['order_id'], $ticket['lines'], 'kitchen', 'sale', $ticket['business_date'], CarbonImmutable::parse($ticket['sent_at']), 'kitchen_ticket', $ticket['id'], null, $ticket['sent_by'], null);
    }

    private function postVoided(string $orderId): void
    {
        $this->ensureCompleted($orderId);
        $order = $this->feed->order($orderId) ?? throw new InventoryException('ORDER_NOT_FOUND', 'Transaksi belum tersedia.');
        if (! $order['was_paid'] || $order['voided_at'] === null || $order['void_stock_action'] === 'waste') {
            return;
        }
        $this->returnLines(
            array_fill_keys(array_column($order['lines'], 'id'), null),
            'void',
            $order['void_business_date'] ?? $order['business_date'],
            CarbonImmutable::parse($order['voided_at']),
            'order_void',
            $order['id'],
            $order['receipt_no'],
            $order['voided_by'],
            'Void transaksi '.$order['receipt_no'],
        );
    }

    private function postRefund(string $refundId): void
    {
        $refund = $this->feed->refund($refundId) ?? throw new InventoryException('REFUND_NOT_FOUND', 'Refund belum tersedia.');
        $this->ensureCompleted($refund['order_id']);
        if ($refund['stock_action'] !== 'return') {
            return;
        }
        $qtys = [];
        foreach ($refund['lines'] as $l) {
            $qtys[$l['line_id']] = BigDecimal::of($l['qty']);
        }
        $order = $this->feed->order($refund['order_id']);
        $this->returnLines(
            $qtys,
            'refund:'.$refundId,
            $refund['business_date'],
            CarbonImmutable::parse($refund['occurred_at']),
            'refund',
            $refund['id'],
            $order['receipt_no'] ?? null,
            $refund['refunded_by'],
            'Refund kembali ke stok',
        );
    }

    /** Pastikan pemotongan saat lunas sudah dicatat sebelum mengembalikan stok (urutan event bisa tertukar saat diulang). */
    private function ensureCompleted(string $orderId): void
    {
        $key = 'completed:'.$orderId;
        if ($this->status($key) === 'posted') {
            return;
        }
        $this->postCompleted($orderId);
        $this->mark($key, 'completed', $orderId, $orderId, 'posted', null);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function postLines(Outlet $outlet, string $orderId, array $lines, string $source, string $type, string $businessDate, CarbonImmutable $at, string $refType, string $refId, ?string $refNo, ?string $by, ?string $reason): void
    {
        if ($lines === []) {
            return;
        }
        $companyId = $this->context->requireCompanyId();
        $posted = DB::table('stock_line_postings')
            ->where('company_id', $companyId)
            ->whereIn('line_id', array_column($lines, 'id'))
            ->pluck('line_id')
            ->flip();
        $lines = array_values(array_filter($lines, fn (array $l) => ! $posted->has($l['id'])));
        if ($lines === []) {
            return;
        }

        $this->recipes->prepare($lines);
        $entries = [];
        $postings = [];
        foreach ($lines as $line) {
            $location = $this->locations->forSale($outlet, $line['kitchen_station_id'] ?? null);
            $usage = $this->recipes->forLine($line);
            foreach ($usage as $ingredientId => $qty) {
                $entries[] = [
                    'location' => $location,
                    'ingredient_id' => $ingredientId,
                    'qty' => $qty->negated(),
                    'type' => $type,
                    'reference_type' => $refType,
                    'reference_id' => $refId,
                    'reference_no' => $refNo,
                    'source_key' => 'line:'.$line['id'].':'.$ingredientId,
                    'reason' => $reason,
                    'business_date' => $businessDate,
                    'occurred_at' => $at,
                    'created_by' => $by,
                ];
            }
            $postings[$line['id']] = [
                'company_id' => $companyId,
                'line_id' => $line['id'],
                'order_id' => $orderId,
                'outlet_id' => $outlet->id,
                'location_id' => $location->id,
                'source' => $source,
                'type' => $type,
                'qty' => (string) BigDecimal::of((string) $line['qty'])->toScale(3),
                'returned_qty' => '0',
                'usage' => $usage,
                'business_date' => $businessDate,
                'posted_at' => now(),
            ];
        }

        $costs = [];
        foreach ($this->ledger->post($entries) as $movement) {
            $costs[(string) $movement->source_key] = (string) $movement->unit_cost;
        }

        $rows = [];
        foreach ($postings as $lineId => $p) {
            $consumption = [];
            foreach ($p['usage'] as $ingredientId => $qty) {
                $consumption[] = [
                    'ingredient_id' => $ingredientId,
                    'qty' => (string) $qty,
                    'unit_cost' => $costs['line:'.$lineId.':'.$ingredientId] ?? '0',
                ];
            }
            unset($p['usage']);
            $rows[] = $p + ['consumption' => json_encode($consumption)];
        }
        DB::table('stock_line_postings')->insert($rows);
    }

    /**
     * @param  array<string, BigDecimal|null>  $lineQtys  line_id => jumlah dikembalikan (null = seluruh sisa)
     */
    private function returnLines(array $lineQtys, string $prefix, string $businessDate, CarbonImmutable $at, string $refType, string $refId, ?string $refNo, ?string $by, string $reason): void
    {
        if ($lineQtys === []) {
            return;
        }
        $companyId = $this->context->requireCompanyId();
        $postings = DB::table('stock_line_postings')
            ->where('company_id', $companyId)
            ->whereIn('line_id', array_keys($lineQtys))
            ->where('type', 'sale')
            ->orderBy('line_id')
            ->lockForUpdate()
            ->get();
        $locations = StockLocation::query()->whereIn('id', $postings->pluck('location_id')->unique()->all())->get()->keyBy('id');

        $entries = [];
        foreach ($postings as $p) {
            $qty = BigDecimal::of((string) $p->qty);
            $remaining = $qty->minus((string) $p->returned_qty);
            $requested = $lineQtys[$p->line_id] ?? null;
            $return = $requested === null || $requested->isGreaterThan($remaining) ? $remaining : $requested;
            if (! $return->isPositive()) {
                continue;
            }
            $fraction = $return->dividedBy($qty, 10, RoundingMode::HALF_UP);
            /** @var list<array{ingredient_id: string, qty: string, unit_cost: string}> $consumption */
            $consumption = json_decode((string) $p->consumption, true);
            foreach ($consumption as $c) {
                $entries[] = [
                    'location' => $locations->get($p->location_id),
                    'ingredient_id' => $c['ingredient_id'],
                    'qty' => BigDecimal::of($c['qty'])->multipliedBy($fraction)->toScale(StockLedger::QTY_SCALE, RoundingMode::HALF_UP),
                    'type' => 'sale_return',
                    'unit_cost' => $c['unit_cost'],
                    'reference_type' => $refType,
                    'reference_id' => $refId,
                    'reference_no' => $refNo,
                    'source_key' => $prefix.':'.$p->line_id.':'.$c['ingredient_id'],
                    'reason' => $reason,
                    'business_date' => $businessDate,
                    'occurred_at' => $at,
                    'created_by' => $by,
                ];
            }
            DB::table('stock_line_postings')
                ->where('company_id', $companyId)
                ->where('line_id', $p->line_id)
                ->update(['returned_qty' => (string) BigDecimal::of((string) $p->returned_qty)->plus($return)->toScale(3)]);
        }

        $this->ledger->post(array_values(array_filter($entries, fn (array $e) => ! BigDecimal::of((string) $e['qty'])->isZero())));
    }

    private function outlet(string $id): Outlet
    {
        return Outlet::withTrashed()->findOrFail($id);
    }
}
