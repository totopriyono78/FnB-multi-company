<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Events\StockBelowMinimum;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Satu-satunya jalur perubahan saldo stok (FR-INV-07, FR-INV-09, ADR 0005).
 *
 * - Moving average: HPP rata-rata berubah hanya saat barang masuk dengan harga (penerimaan, transfer masuk,
 *   pengembalian, penyesuaian plus). Barang keluar memakai HPP rata-rata saat itu.
 * - Saldo dikunci baris (FOR UPDATE) dengan urutan tetap agar posting bersamaan tidak saling menimpa.
 * - `source_key` membuat posting otomatis idempoten.
 */
class StockLedger
{
    public const QTY_SCALE = 4;

    public const COST_SCALE = 6;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  list<array{
     *     location: StockLocation,
     *     ingredient_id: string,
     *     qty: BigDecimal|string,
     *     type: string,
     *     unit_cost?: BigDecimal|string|null,
     *     reference_type: string,
     *     reference_id: string,
     *     reference_no?: string|null,
     *     source_key?: string|null,
     *     reason?: string|null,
     *     business_date: string,
     *     occurred_at: CarbonImmutable,
     *     created_by?: string|null,
     *     flags?: list<string>,
     * }>  $entries
     * @param  bool  $enforceStock  tolak bila saldo menjadi minus (dokumen back-office di outlet yang melarang stok minus)
     * @return list<StockMovement>
     */
    public function post(array $entries, bool $enforceStock = false): array
    {
        if ($entries === []) {
            return [];
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger::post wajib dipanggil di dalam transaksi database.');
        }
        $companyId = $this->context->requireCompanyId();

        // Lewati posting otomatis yang sudah pernah dicatat.
        $keys = array_values(array_filter(array_map(fn (array $e) => $e['source_key'] ?? null, $entries)));
        $done = $keys === [] ? [] : array_flip(StockMovement::query()->whereIn('source_key', $keys)->pluck('source_key')->all());
        $entries = array_values(array_filter($entries, fn (array $e) => ! isset($e['source_key']) || ! isset($done[$e['source_key']])));
        if ($entries === []) {
            return [];
        }

        $pairs = [];
        foreach ($entries as $e) {
            $pairs[$e['location']->id.'|'.$e['ingredient_id']] = [$e['location']->id, $e['ingredient_id']];
        }
        ksort($pairs);
        $now = now();
        $rows = [];
        foreach ($pairs as [$locationId, $ingredientId]) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'location_id' => $locationId,
                'ingredient_id' => $ingredientId,
                'qty' => '0',
                'avg_cost' => '0',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        StockBalance::query()->insertOrIgnore($rows);

        /** @var array<string, StockBalance> $balances */
        $balances = [];
        foreach (array_chunk(array_values($pairs), 200) as $chunk) {
            $query = StockBalance::query()->where(function ($q) use ($chunk): void {
                foreach ($chunk as [$locationId, $ingredientId]) {
                    $q->orWhere(fn ($w) => $w->where('location_id', $locationId)->where('ingredient_id', $ingredientId));
                }
            })->orderBy('location_id')->orderBy('ingredient_id')->lockForUpdate();
            foreach ($query->get() as $balance) {
                $balances[$balance->location_id.'|'.$balance->ingredient_id] = $balance;
            }
        }

        $ingredients = Ingredient::withTrashed()
            ->whereIn('id', array_values(array_unique(array_column(array_values($pairs), 1))))
            ->get(['id', 'name', 'base_unit', 'min_stock', 'last_cost'])
            ->keyBy('id');

        $state = [];
        foreach ($balances as $key => $b) {
            $state[$key] = ['qty' => BigDecimal::of((string) $b->qty), 'avg' => BigDecimal::of((string) $b->avg_cost), 'start' => BigDecimal::of((string) $b->qty)];
        }

        $movements = [];
        $insert = [];
        $lastCosts = [];
        foreach ($entries as $e) {
            $key = $e['location']->id.'|'.$e['ingredient_id'];
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($e['ingredient_id']);
            if ($ingredient === null || ! isset($state[$key])) {
                throw new InventoryException('INGREDIENT_UNKNOWN', 'Bahan tidak dikenal.', 422);
            }
            $qty = BigDecimal::of((string) $e['qty'])->toScale(self::QTY_SCALE, RoundingMode::HALF_UP);
            if ($qty->isZero()) {
                continue;
            }
            $old = $state[$key];
            $given = isset($e['unit_cost']) ? BigDecimal::of((string) $e['unit_cost']) : null;
            $fallback = $ingredient->last_cost !== null ? BigDecimal::of((string) $ingredient->last_cost) : BigDecimal::zero();
            $current = $old['avg']->isZero() ? $fallback : $old['avg'];
            $cost = ($given ?? $current)->toScale(self::COST_SCALE, RoundingMode::HALF_UP);
            if ($cost->isNegative()) {
                throw new InventoryException('INVALID_COST', 'Harga pokok tidak boleh negatif.', 422);
            }

            $newQty = $old['qty']->plus($qty);
            $avg = $old['avg'];
            if ($qty->isPositive()) {
                $avg = $old['qty']->isLessThanOrEqualTo(0) || $avg->isZero()
                    ? $cost
                    : $old['qty']->multipliedBy($avg)->plus($qty->multipliedBy($cost))
                        ->dividedBy($newQty, self::COST_SCALE, RoundingMode::HALF_UP);
                if ($e['type'] === 'receipt') {
                    $lastCosts[$ingredient->id] = (string) $cost;
                } elseif ($given !== null && $cost->isPositive() && $ingredient->last_cost === null && ! isset($lastCosts[$ingredient->id])) {
                    // Saldo awal / penyesuaian plus berharga menjadi acuan harga bila bahan belum pernah dibeli.
                    $lastCosts[$ingredient->id] = (string) $cost;
                }
            } elseif ($avg->isZero() && $cost->isPositive()) {
                // Bahan belum pernah diterima di lokasi ini: pakai harga beli terakhir sebagai acuan.
                $avg = $cost;
            }

            $flags = $e['flags'] ?? [];
            if ($newQty->isNegative()) {
                if ($enforceStock && $qty->isNegative()) {
                    throw new InventoryException('STOCK_INSUFFICIENT', "Stok {$ingredient->name} tidak cukup di {$e['location']->name}. Outlet ini tidak mengizinkan stok minus.", 409, details: [
                        'ingredient_id' => $ingredient->id,
                        'available' => (string) $old['qty'],
                        'requested' => (string) $qty->negated(),
                    ]);
                }
                $flags[] = 'negative_stock';
            }

            $state[$key] = ['qty' => $newQty, 'avg' => $avg, 'start' => $old['start']];
            $row = [
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'outlet_id' => $e['location']->outlet_id,
                'location_id' => $e['location']->id,
                'ingredient_id' => $ingredient->id,
                'type' => $e['type'],
                'qty' => (string) $qty,
                'unit_cost' => (string) $cost,
                'value' => (string) $qty->multipliedBy($cost)->toScale(2, RoundingMode::HALF_UP),
                'balance_after' => (string) $newQty,
                'avg_cost_after' => (string) $avg,
                'reference_type' => $e['reference_type'],
                'reference_id' => $e['reference_id'],
                'reference_no' => $e['reference_no'] ?? null,
                'source_key' => $e['source_key'] ?? null,
                'reason' => isset($e['reason']) ? mb_substr((string) $e['reason'], 0, 200) : null,
                'business_date' => $e['business_date'],
                'occurred_at' => $e['occurred_at']->utc(),
                'created_by' => $e['created_by'] ?? null,
                'flags' => json_encode($flags),
                'created_at' => $now,
            ];
            $insert[] = $row;
            $movements[] = (new StockMovement)->forceFill(array_merge($row, ['flags' => $flags]));
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            StockMovement::query()->insert($chunk);
        }

        foreach ($state as $key => $s) {
            $balance = $balances[$key];
            if ($s['qty']->isEqualTo($s['start']) && $s['avg']->isEqualTo(BigDecimal::of((string) $balance->avg_cost))) {
                continue;
            }
            StockBalance::query()->whereKey($balance->id)->update([
                'qty' => (string) $s['qty'],
                'avg_cost' => (string) $s['avg'],
                'last_movement_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var Ingredient $ingredient */
            $ingredient = $ingredients->get($balance->ingredient_id);
            $min = BigDecimal::of((string) ($balance->min_qty ?? $ingredient->min_stock));
            if ($min->isPositive() && $s['start']->isGreaterThanOrEqualTo($min) && $s['qty']->isLessThan($min)) {
                $event = new StockBelowMinimum($companyId, $balance->location_id, $balance->ingredient_id, (string) $s['qty'], (string) $min);
                DB::afterCommit(fn () => event($event));
            }
        }

        foreach ($lastCosts as $ingredientId => $cost) {
            Ingredient::withTrashed()->whereKey($ingredientId)->toBase()->update(['last_cost' => $cost]);
        }

        return $movements;
    }

    /** HPP rata-rata saat ini untuk menilai bahan (0 bila belum ada harga sama sekali). */
    public function currentCost(string $locationId, string $ingredientId): BigDecimal
    {
        $avg = StockBalance::query()->where('location_id', $locationId)->where('ingredient_id', $ingredientId)->value('avg_cost');
        if ($avg !== null && BigDecimal::of((string) $avg)->isPositive()) {
            return BigDecimal::of((string) $avg);
        }
        $last = Ingredient::withTrashed()->whereKey($ingredientId)->value('last_cost');

        return BigDecimal::of((string) ($last ?? '0'));
    }

    public function balance(string $locationId, string $ingredientId): BigDecimal
    {
        return BigDecimal::of((string) (StockBalance::query()->where('location_id', $locationId)->where('ingredient_id', $ingredientId)->value('qty') ?? '0'));
    }
}
