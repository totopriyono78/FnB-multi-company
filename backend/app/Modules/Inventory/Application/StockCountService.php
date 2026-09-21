<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockBalance;
use App\Modules\Inventory\Domain\Models\StockCount;
use App\Modules\Inventory\Domain\Models\StockCountLine;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Shared\Support\DocumentNumber;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock opname (FR-INV-06, SRS §9.4): saldo teoritis dibekukan saat mulai, staf menginput jumlah fisik,
 * manajer menyetujui → selisih diposting sebagai mutasi `count` dengan HPP saat mulai.
 */
class StockCountService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockDocumentService $documents,
        private readonly InventoryAccess $access,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{location_id: string, scope: string, ingredient_ids?: list<string>|null, notes?: string|null}  $data
     */
    public function start(User $actor, array $data): StockCount
    {
        $location = $this->documents->location($data['location_id'], 'location_id');
        if (! $this->access->canManageOutlet($actor, $location->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin melakukan opname di outlet ini.');
        }

        if ($data['scope'] === 'partial') {
            $ids = array_values(array_unique($data['ingredient_ids'] ?? []));
            if ($ids === []) {
                throw new InventoryException('INGREDIENTS_REQUIRED', 'Pilih bahan yang akan dihitung.', 422, 'ingredient_ids');
            }
            $this->documents->stockable($ids);
        } else {
            // Semua bahan stok aktif + bahan yang masih punya saldo di lokasi ini.
            $exploded = Recipe::query()->where('target_type', Recipe::INGREDIENT)->whereHas('lines')->pluck('target_id');
            $ids = Ingredient::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('kind', Ingredient::RAW)->orWhereNotIn('id', $exploded))
                ->pluck('id')
                ->merge(StockBalance::query()->where('location_id', $location->id)->where('qty', '<>', 0)->pluck('ingredient_id'))
                ->unique()->values()->all();
            if ($ids === []) {
                throw new InventoryException('NO_INGREDIENTS', 'Belum ada bahan untuk dihitung. Tambahkan bahan baku terlebih dahulu.', 422, 'scope');
            }
        }

        try {
            return DB::transaction(function () use ($actor, $data, $location, $ids): StockCount {
                $outlet = $location->outlet;
                $at = now()->toImmutable();
                $count = new StockCount;
                $count->forceFill([
                    'id' => (string) Str::uuid7(),
                    'number' => DocumentNumber::next('OPN', $outlet->code, $at->setTimezone($outlet->timezone)),
                    'outlet_id' => $outlet->id,
                    'location_id' => $location->id,
                    'scope' => $data['scope'],
                    'status' => StockCount::COUNTING,
                    'notes' => $data['notes'] ?? null,
                    'started_at' => $at,
                    'started_by' => $actor->id,
                ])->save();

                $balances = StockBalance::query()->where('location_id', $location->id)->whereIn('ingredient_id', $ids)->get()->keyBy('ingredient_id');
                $rows = [];
                foreach ($ids as $ingredientId) {
                    /** @var StockBalance|null $balance */
                    $balance = $balances->get($ingredientId);
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $count->company_id,
                        'stock_count_id' => $count->id,
                        'ingredient_id' => $ingredientId,
                        'system_qty' => (string) ($balance->qty ?? '0'),
                        'unit_cost' => (string) $this->ledger->currentCost($location->id, $ingredientId),
                    ];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('stock_count_lines')->insert($chunk);
                }
                $this->audit->log('stock.count_started', $count, new: ['number' => $count->number, 'scope' => $count->scope, 'lines' => count($rows)], userId: $actor->id);

                return $count->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            throw new InventoryException('COUNT_IN_PROGRESS', 'Masih ada opname yang belum selesai di lokasi ini.');
        }
    }

    /**
     * @param  list<array{ingredient_id: string, counted_qty: string|int|float|null, note?: string|null}>  $lines
     */
    public function record(User $actor, StockCount $count, array $lines): StockCount
    {
        $this->assertManage($actor, $count);

        return DB::transaction(function () use ($count, $lines): StockCount {
            $locked = $this->lock($count);
            if ($locked->status !== StockCount::COUNTING) {
                throw new InventoryException('COUNT_NOT_EDITABLE', 'Opname sudah diajukan atau selesai; hasil hitung tidak dapat diubah.');
            }
            $byIngredient = $locked->lines->keyBy('ingredient_id');
            foreach ($lines as $i => $line) {
                /** @var StockCountLine|null $row */
                $row = $byIngredient->get($line['ingredient_id']);
                if ($row === null) {
                    throw new InventoryException('LINE_UNKNOWN', 'Bahan tidak termasuk dalam opname ini.', 422, "lines.{$i}.ingredient_id");
                }
                $counted = $line['counted_qty'] === null ? null : BigDecimal::of((string) $line['counted_qty']);
                if ($counted !== null && $counted->isNegative()) {
                    throw new InventoryException('INVALID_QTY', 'Jumlah fisik tidak boleh minus.', 422, "lines.{$i}.counted_qty");
                }
                $difference = $counted?->minus((string) $row->system_qty);
                StockCountLine::query()->whereKey($row->id)->update([
                    'counted_qty' => $counted === null ? null : (string) $counted->toScale(4),
                    'difference' => $difference === null ? null : (string) $difference->toScale(4),
                    'variance_value' => $difference === null ? null : (string) $difference->multipliedBy((string) $row->unit_cost)->toScale(2, RoundingMode::HALF_UP),
                    'note' => array_key_exists('note', $line) ? $line['note'] : $row->note,
                ]);
            }

            return $locked->refresh()->load('lines');
        });
    }

    public function submit(User $actor, StockCount $count): StockCount
    {
        $this->assertManage($actor, $count);

        return DB::transaction(function () use ($actor, $count): StockCount {
            $locked = $this->lock($count);
            if ($locked->status !== StockCount::COUNTING) {
                throw new InventoryException('COUNT_NOT_EDITABLE', 'Opname ini tidak sedang dalam tahap hitung.');
            }
            $missing = $locked->lines->whereNull('counted_qty')->count();
            if ($missing > 0) {
                throw new InventoryException('COUNT_INCOMPLETE', "Masih ada {$missing} bahan yang belum dihitung.", 422, 'lines', ['uncounted' => $missing]);
            }
            $variance = $locked->lines->reduce(fn (BigDecimal $c, StockCountLine $l) => $c->plus((string) $l->variance_value), BigDecimal::zero());
            $locked->forceFill([
                'status' => StockCount::SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
                'variance_value' => (string) $variance->toScale(2),
            ])->save();
            $this->audit->log('stock.count_submitted', $locked, new: ['number' => $locked->number, 'variance_value' => $locked->variance_value], userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    public function approve(User $actor, StockCount $count, ?string $note): StockCount
    {
        $count->loadMissing('location.outlet');
        if (! $this->access->canApproveCount($actor, $count->location->outlet)) {
            throw new AuthorizationException('Persetujuan opname hanya oleh manajer yang berwenang.');
        }

        return DB::transaction(function () use ($actor, $count, $note): StockCount {
            $locked = $this->lock($count);
            if ($locked->status !== StockCount::SUBMITTED) {
                throw new InventoryException('COUNT_NOT_SUBMITTED', 'Opname belum diajukan atau sudah diputuskan.');
            }
            $location = $count->location;
            $at = now()->toImmutable();
            $businessDate = $this->calendar->businessDate($location->outlet, $at)->format('Y-m-d');
            $entries = [];
            foreach ($locked->lines as $line) {
                $difference = BigDecimal::of((string) $line->difference);
                if ($difference->isZero()) {
                    continue;
                }
                $entries[] = [
                    'location' => $location,
                    'ingredient_id' => $line->ingredient_id,
                    'qty' => $difference,
                    'unit_cost' => (string) $line->unit_cost,
                    'type' => 'count',
                    'reference_type' => 'stock_count',
                    'reference_id' => $locked->id,
                    'reference_no' => $locked->number,
                    'source_key' => 'count:'.$locked->id.':'.$line->ingredient_id,
                    'reason' => 'Selisih opname '.$locked->number,
                    'business_date' => $businessDate,
                    'occurred_at' => $at,
                    'created_by' => $actor->id,
                ];
            }
            $this->ledger->post($entries);
            $selfApproved = $locked->submitted_by === $actor->id || $locked->started_by === $actor->id;
            $locked->forceFill([
                'status' => StockCount::APPROVED,
                'decided_at' => $at,
                'decided_by' => $actor->id,
                'decision_note' => $note,
            ])->save();
            $this->audit->log('stock.count_approved', $locked, new: [
                'number' => $locked->number,
                'variance_value' => $locked->variance_value,
                'adjusted_lines' => count($entries),
                'self_approved' => $selfApproved,
            ], reason: $note, userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    /** Kembalikan ke tahap hitung (hitung ulang) dengan catatan manajer. */
    public function requestRecount(User $actor, StockCount $count, string $note): StockCount
    {
        $count->loadMissing('location.outlet');
        if (! $this->access->canApproveCount($actor, $count->location->outlet)) {
            throw new AuthorizationException('Keputusan opname hanya oleh manajer yang berwenang.');
        }

        return DB::transaction(function () use ($actor, $count, $note): StockCount {
            $locked = $this->lock($count);
            if ($locked->status !== StockCount::SUBMITTED) {
                throw new InventoryException('COUNT_NOT_SUBMITTED', 'Opname belum diajukan atau sudah diputuskan.');
            }
            $locked->forceFill([
                'status' => StockCount::COUNTING,
                'submitted_at' => null,
                'submitted_by' => null,
                'decision_note' => $note,
            ])->save();
            $this->audit->log('stock.count_recount_requested', $locked, new: ['number' => $locked->number], reason: $note, userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    public function cancel(User $actor, StockCount $count, string $reason): StockCount
    {
        $this->assertManage($actor, $count);

        return DB::transaction(function () use ($actor, $count, $reason): StockCount {
            $locked = $this->lock($count);
            if (! in_array($locked->status, [StockCount::COUNTING, StockCount::SUBMITTED], true)) {
                throw new InventoryException('COUNT_FINAL', 'Opname yang sudah selesai tidak dapat dibatalkan.');
            }
            $locked->forceFill([
                'status' => StockCount::CANCELLED,
                'decided_at' => now(),
                'decided_by' => $actor->id,
                'decision_note' => $reason,
            ])->save();
            $this->audit->log('stock.count_cancelled', $locked, new: ['number' => $locked->number], reason: $reason, userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    private function assertManage(User $actor, StockCount $count): void
    {
        $count->loadMissing('location.outlet');
        if (! $this->access->canManageOutlet($actor, $count->location->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin mengelola opname di outlet ini.');
        }
    }

    private function lock(StockCount $count): StockCount
    {
        /** @var StockCount $locked */
        $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();

        return $locked->load('lines');
    }
}
