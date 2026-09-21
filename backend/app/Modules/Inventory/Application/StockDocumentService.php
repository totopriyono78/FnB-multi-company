<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockAdjustment;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use App\Modules\Inventory\Domain\Models\StockTransferLine;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Shared\Support\DocumentNumber;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Penyesuaian stok, waste, dan transfer antar gudang/outlet (FR-INV-05).
 */
class StockDocumentService
{
    /** Batas pencatatan mundur dokumen back-office. */
    public const MAX_BACKDATE_DAYS = 7;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly InventoryAccess $access,
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{location_id: string, type: string, reason_code: string, notes?: string|null, occurred_at?: string|null,
     *     lines: list<array{ingredient_id: string, qty: string|int|float, unit_cost?: string|int|float|null, note?: string|null}>}  $data
     */
    public function adjust(User $actor, array $data): StockAdjustment
    {
        $location = $this->location($data['location_id'], 'location_id');
        $outlet = $location->outlet;
        if (! $this->access->canManageOutlet($actor, $outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin mengelola stok outlet ini.');
        }
        $type = $data['type'];
        $reasons = $type === 'waste' ? StockAdjustment::WASTE_REASONS : StockAdjustment::ADJUSTMENT_REASONS;
        if (! array_key_exists($data['reason_code'], $reasons)) {
            throw new InventoryException('INVALID_REASON', 'Alasan tidak dikenal.', 422, 'reason_code');
        }
        if ($data['reason_code'] === 'other' && trim((string) ($data['notes'] ?? '')) === '') {
            throw new InventoryException('NOTES_REQUIRED', 'Isi keterangan untuk alasan "Lainnya".', 422, 'notes');
        }
        $at = $this->time($data['occurred_at'] ?? null);
        $ingredients = $this->stockable(array_column($data['lines'], 'ingredient_id'));

        return DB::transaction(function () use ($actor, $data, $location, $outlet, $type, $at, $ingredients): StockAdjustment {
            $id = (string) Str::uuid7();
            $number = DocumentNumber::next($type === 'waste' ? 'WST' : 'ADJ', $outlet->code, $at->setTimezone($outlet->timezone));
            $businessDate = $this->calendar->businessDate($outlet, $at)->format('Y-m-d');
            $flags = $data['reason_code'] === 'opening' ? ['opening'] : [];

            $entries = [];
            foreach ($data['lines'] as $i => $line) {
                $qty = BigDecimal::of((string) $line['qty']);
                if ($type === 'waste') {
                    if (! $qty->isPositive()) {
                        throw new InventoryException('INVALID_QTY', 'Jumlah waste harus lebih dari 0.', 422, "lines.{$i}.qty");
                    }
                    $qty = $qty->negated();
                } elseif ($qty->isZero()) {
                    throw new InventoryException('INVALID_QTY', 'Jumlah penyesuaian tidak boleh 0.', 422, "lines.{$i}.qty");
                }
                $cost = $line['unit_cost'] ?? null;
                if ($cost !== null && $qty->isNegative()) {
                    // Barang keluar selalu dinilai HPP rata-rata.
                    $cost = null;
                }
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients->get($line['ingredient_id']);
                $entries[] = [
                    'location' => $location,
                    'ingredient_id' => $ingredient->id,
                    'qty' => $qty,
                    'unit_cost' => $cost === null ? null : (string) $cost,
                    'type' => $type,
                    'reference_type' => 'stock_adjustment',
                    'reference_id' => $id,
                    'reference_no' => $number,
                    'reason' => StockAdjustment::reasonLabel($type, $data['reason_code']).(isset($line['note']) && $line['note'] !== '' ? ' · '.$line['note'] : ''),
                    'business_date' => $businessDate,
                    'occurred_at' => $at,
                    'created_by' => $actor->id,
                    'flags' => $flags,
                ];
            }

            $movements = $this->ledger->post($entries, ! $outlet->allow_negative_stock);
            $total = BigDecimal::zero();
            $rows = [];
            foreach ($movements as $n => $m) {
                $total = $total->plus((string) $m->value);
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $m->company_id,
                    'stock_adjustment_id' => $id,
                    'ingredient_id' => $m->ingredient_id,
                    'qty' => $m->qty,
                    'unit_cost' => $m->unit_cost,
                    'value' => $m->value,
                    'note' => isset($data['lines'][$n]['note']) ? mb_substr((string) $data['lines'][$n]['note'], 0, 200) : null,
                ];
            }

            $doc = new StockAdjustment;
            $doc->forceFill([
                'id' => $id,
                'number' => $number,
                'outlet_id' => $outlet->id,
                'location_id' => $location->id,
                'type' => $type,
                'reason_code' => $data['reason_code'],
                'notes' => $data['notes'] ?? null,
                'total_value' => (string) $total->toScale(2),
                'business_date' => $businessDate,
                'occurred_at' => $at,
                'created_by' => $actor->id,
            ])->save();
            DB::table('stock_adjustment_lines')->insert($rows);

            $this->audit->log($type === 'waste' ? 'stock.waste_recorded' : 'stock.adjusted', $doc, new: [
                'number' => $number,
                'reason_code' => $data['reason_code'],
                'total_value' => $doc->total_value,
                'lines' => count($rows),
            ], reason: $data['notes'] ?? null, userId: $actor->id);

            return $doc->load('lines');
        });
    }

    /**
     * @param  array{from_location_id: string, to_location_id: string, notes?: string|null,
     *     lines: list<array{ingredient_id: string, qty: string|int|float, note?: string|null}>}  $data
     */
    public function send(User $actor, array $data): StockTransfer
    {
        $from = $this->location($data['from_location_id'], 'from_location_id');
        $to = $this->location($data['to_location_id'], 'to_location_id');
        if ($from->id === $to->id) {
            throw new InventoryException('SAME_LOCATION', 'Lokasi asal dan tujuan tidak boleh sama.', 422, 'to_location_id');
        }
        if (! $this->access->canManageOutlet($actor, $from->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin mengirim stok dari outlet ini.');
        }
        $ingredients = $this->stockable(array_column($data['lines'], 'ingredient_id'));
        $at = now()->toImmutable();

        return DB::transaction(function () use ($actor, $data, $from, $to, $at, $ingredients): StockTransfer {
            $id = (string) Str::uuid7();
            $outlet = $from->outlet;
            $number = DocumentNumber::next('TRF', $outlet->code, $at->setTimezone($outlet->timezone));
            $entries = [];
            foreach ($data['lines'] as $i => $line) {
                $qty = BigDecimal::of((string) $line['qty']);
                if (! $qty->isPositive()) {
                    throw new InventoryException('INVALID_QTY', 'Jumlah kirim harus lebih dari 0.', 422, "lines.{$i}.qty");
                }
                $entries[] = [
                    'location' => $from,
                    'ingredient_id' => $ingredients->get($line['ingredient_id'])->id,
                    'qty' => $qty->negated(),
                    'type' => 'transfer_out',
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $id,
                    'reference_no' => $number,
                    'reason' => 'Kirim ke '.$to->outlet->name.' · '.$to->name,
                    'business_date' => $this->calendar->businessDate($outlet, $at)->format('Y-m-d'),
                    'occurred_at' => $at,
                    'created_by' => $actor->id,
                ];
            }
            $movements = $this->ledger->post($entries, ! $outlet->allow_negative_stock);

            $total = BigDecimal::zero();
            $rows = [];
            foreach ($movements as $n => $m) {
                $total = $total->minus((string) $m->value);
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $m->company_id,
                    'stock_transfer_id' => $id,
                    'ingredient_id' => $m->ingredient_id,
                    'qty_sent' => (string) BigDecimal::of((string) $m->qty)->negated(),
                    'qty_received' => null,
                    'unit_cost' => $m->unit_cost,
                    'note' => isset($data['lines'][$n]['note']) ? mb_substr((string) $data['lines'][$n]['note'], 0, 200) : null,
                ];
            }

            $transfer = new StockTransfer;
            $transfer->forceFill([
                'id' => $id,
                'number' => $number,
                'from_outlet_id' => $from->outlet_id,
                'from_location_id' => $from->id,
                'to_outlet_id' => $to->outlet_id,
                'to_location_id' => $to->id,
                'status' => StockTransfer::IN_TRANSIT,
                'notes' => $data['notes'] ?? null,
                'total_value' => (string) $total->toScale(2),
                'sent_by' => $actor->id,
                'sent_at' => $at,
            ])->save();
            DB::table('stock_transfer_lines')->insert($rows);
            $this->audit->log('stock.transfer_sent', $transfer, new: ['number' => $number, 'to_location_id' => $to->id, 'total_value' => $transfer->total_value], userId: $actor->id);

            return $transfer->load('lines');
        });
    }

    /**
     * @param  array{note?: string|null, lines?: list<array{line_id: string, qty_received: string|int|float}>}  $data
     */
    public function receive(User $actor, StockTransfer $transfer, array $data): StockTransfer
    {
        $transfer->loadMissing('toLocation.outlet');
        if (! $this->access->canManageOutlet($actor, $transfer->toLocation->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin menerima stok di outlet tujuan.');
        }

        return DB::transaction(function () use ($actor, $transfer, $data): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with('lines')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StockTransfer::IN_TRANSIT) {
                throw new InventoryException('TRANSFER_NOT_IN_TRANSIT', 'Transfer ini sudah diterima atau dibatalkan.');
            }
            $given = collect($data['lines'] ?? [])->keyBy('line_id');
            foreach ($given->keys() as $lineId) {
                if (! $locked->lines->contains('id', $lineId)) {
                    throw new InventoryException('LINE_UNKNOWN', 'Baris transfer tidak dikenal.', 422, 'lines');
                }
            }
            $to = $transfer->toLocation;
            $at = now()->toImmutable();
            $outlet = $to->outlet;
            $entries = [];
            $variance = false;
            foreach ($locked->lines as $line) {
                $sent = BigDecimal::of((string) $line->qty_sent);
                $received = $given->has($line->id) ? BigDecimal::of((string) $given->get($line->id)['qty_received']) : $sent;
                if ($received->isNegative() || $received->isGreaterThan($sent)) {
                    throw new InventoryException('INVALID_QTY', 'Jumlah diterima harus antara 0 dan jumlah dikirim.', 422, 'lines');
                }
                $variance = $variance || ! $received->isEqualTo($sent);
                StockTransferLine::query()->whereKey($line->id)->update(['qty_received' => (string) $received->toScale(4)]);
                if ($received->isPositive()) {
                    $entries[] = [
                        'location' => $to,
                        'ingredient_id' => $line->ingredient_id,
                        'qty' => $received,
                        'unit_cost' => (string) $line->unit_cost,
                        'type' => 'transfer_in',
                        'reference_type' => 'stock_transfer',
                        'reference_id' => $locked->id,
                        'reference_no' => $locked->number,
                        'reason' => 'Terima dari transfer '.$locked->number,
                        'business_date' => $this->calendar->businessDate($outlet, $at)->format('Y-m-d'),
                        'occurred_at' => $at,
                        'created_by' => $actor->id,
                    ];
                }
            }
            $this->ledger->post($entries);
            $locked->forceFill([
                'status' => StockTransfer::RECEIVED,
                'received_by' => $actor->id,
                'received_at' => $at,
                'receive_note' => $data['note'] ?? null,
            ])->save();
            $this->audit->log('stock.transfer_received', $locked, new: ['number' => $locked->number, 'variance' => $variance], reason: $data['note'] ?? null, userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    public function cancelTransfer(User $actor, StockTransfer $transfer, string $reason): StockTransfer
    {
        $transfer->loadMissing('fromLocation.outlet');
        if (! $this->access->canManageOutlet($actor, $transfer->fromLocation->outlet)) {
            throw new AuthorizationException('Anda tidak memiliki izin membatalkan transfer ini.');
        }

        return DB::transaction(function () use ($actor, $transfer, $reason): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->with('lines')->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StockTransfer::IN_TRANSIT) {
                throw new InventoryException('TRANSFER_NOT_IN_TRANSIT', 'Transfer ini sudah diterima atau dibatalkan.');
            }
            $from = $transfer->fromLocation;
            $at = now()->toImmutable();
            $entries = [];
            foreach ($locked->lines as $line) {
                $entries[] = [
                    'location' => $from,
                    'ingredient_id' => $line->ingredient_id,
                    'qty' => (string) $line->qty_sent,
                    'unit_cost' => (string) $line->unit_cost,
                    'type' => 'transfer_in',
                    'reference_type' => 'stock_transfer',
                    'reference_id' => $locked->id,
                    'reference_no' => $locked->number,
                    'reason' => 'Transfer dibatalkan: '.$reason,
                    'business_date' => $this->calendar->businessDate($from->outlet, $at)->format('Y-m-d'),
                    'occurred_at' => $at,
                    'created_by' => $actor->id,
                ];
            }
            $this->ledger->post($entries);
            $locked->forceFill([
                'status' => StockTransfer::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => $at,
                'cancel_reason' => $reason,
            ])->save();
            $this->audit->log('stock.transfer_cancelled', $locked, new: ['number' => $locked->number], reason: $reason, userId: $actor->id);

            return $locked->refresh()->load('lines');
        });
    }

    public function location(string $id, string $field): StockLocation
    {
        /** @var StockLocation|null $location */
        $location = Str::isUuid($id) ? StockLocation::query()->with('outlet')->find($id) : null;
        if ($location === null || ! $location->is_active || $location->outlet->trashed()) {
            throw new InventoryException('LOCATION_UNKNOWN', 'Lokasi stok tidak ditemukan atau nonaktif.', 422, $field);
        }

        return $location;
    }

    /**
     * Bahan yang disimpan sebagai stok (bukan bahan setengah jadi yang selalu dijabarkan dari resepnya).
     *
     * @param  list<string>  $ids
     * @return Collection<string, Ingredient>
     */
    public function stockable(array $ids): Collection
    {
        if (count($ids) !== count(array_unique($ids))) {
            throw new InventoryException('DUPLICATE_INGREDIENT', 'Bahan yang sama tidak boleh dimasukkan dua kali.', 422, 'lines');
        }
        $ingredients = Ingredient::query()->whereIn('id', $ids)->get()->keyBy('id');
        $withRecipe = Recipe::query()->where('target_type', Recipe::INGREDIENT)->whereIn('target_id', $ids)->whereHas('lines')->pluck('target_id')->flip();
        foreach ($ids as $i => $id) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $ingredients->get($id);
            if ($ingredient === null) {
                throw new InventoryException('INGREDIENT_UNKNOWN', 'Bahan tidak ditemukan.', 422, "lines.{$i}.ingredient_id");
            }
            if ($ingredient->isSemi() && $withRecipe->has($id)) {
                throw new InventoryException('NOT_STOCKED', "{$ingredient->name} dihitung dari sub-resep dan tidak disimpan sebagai stok.", 422, "lines.{$i}.ingredient_id");
            }
        }

        /** @var Collection<string, Ingredient> */
        return collect($ingredients->all());
    }

    private function time(?string $value): CarbonImmutable
    {
        $at = $value === null ? now()->toImmutable() : CarbonImmutable::parse($value)->utc();
        if ($at->greaterThan(now()->addMinutes(5))) {
            throw new InventoryException('FUTURE_TIME', 'Waktu tidak boleh di masa depan.', 422, 'occurred_at');
        }
        if ($at->lessThan(now()->subDays(self::MAX_BACKDATE_DAYS))) {
            throw new InventoryException('TOO_OLD', 'Pencatatan mundur maksimal '.self::MAX_BACKDATE_DAYS.' hari. Gunakan stock opname untuk koreksi lama.', 422, 'occurred_at');
        }

        return $at;
    }
}
