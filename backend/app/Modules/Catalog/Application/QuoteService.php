<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Catalog\Domain\Pricing\PricingCalculator;
use App\Modules\Catalog\Domain\Pricing\PromotionEngine;
use App\Modules\Catalog\Domain\Pricing\ScheduleMatcher;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menyusun keranjang dari data menu, menerapkan promo, lalu menghitung total (FR-POS-20).
 * Dipakai back-office (simulasi harga), self-order, dan validasi transaksi yang disinkronkan.
 */
class QuoteService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly PromotionEngine $promotions,
        private readonly PricingCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function quote(Outlet $outlet, array $request): array
    {
        $channel = SalesChannel::query()->where('code', $request['channel_code'] ?? 'dine_in')->where('is_active', true)->first();
        if ($channel === null) {
            throw ValidationException::withMessages(['channel_code' => 'Channel penjualan tidak aktif.']);
        }

        $at = isset($request['at']) ? CarbonImmutable::parse($request['at']) : CarbonImmutable::now();
        $local = $at->setTimezone($outlet->timezone);

        /** @var list<array<string, mixed>> $requested */
        $requested = $request['lines'] ?? [];
        $itemIds = collect($requested)->pluck('item_id')->filter()->unique()->values();

        /** @var Collection<string, Item> $items */
        $items = Item::query()
            ->with(['variants', 'modifierGroups.modifiers', 'bundleGroups.options.item', 'prices'])
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');
        $availability = OutletItemAvailability::query()->where('outlet_id', $outlet->id)->whereIn('item_id', $itemIds)->get()->keyBy('item_id');

        $cartLines = [];
        $calcLines = [];
        $described = [];
        foreach (array_values($requested) as $index => $req) {
            $lineId = (string) ($req['id'] ?? Str::uuid7());
            $item = $items->get($req['item_id']);
            $prefix = "lines.{$index}";

            if ($item === null || ! $item->is_active || $item->brand_id !== $outlet->brand_id) {
                throw ValidationException::withMessages(["{$prefix}.item_id" => 'Menu tidak tersedia di outlet ini.']);
            }
            $avail = $availability->get($item->id);
            if ($avail !== null && ! $avail->is_listed) {
                throw ValidationException::withMessages(["{$prefix}.item_id" => "{$item->name} tidak dijual di outlet ini."]);
            }
            if ($avail !== null && $avail->is_sold_out) {
                throw ValidationException::withMessages(["{$prefix}.item_id" => "{$item->name} sedang habis."]);
            }
            if (! $item->isSoldForChannel($channel->code)) {
                throw ValidationException::withMessages(["{$prefix}.item_id" => "{$item->name} tidak dijual di channel {$channel->name}."]);
            }
            if (! ScheduleMatcher::matches($item->schedule, $local)) {
                throw ValidationException::withMessages(["{$prefix}.item_id" => "{$item->name} belum/tidak tersedia pada jam ini."]);
            }

            $variant = $this->variant($item, $req['variant_id'] ?? null, $prefix);
            $unit = BigDecimal::of($this->prices->resolve($item, $variant, $outlet->id, $channel->id));
            $modifiers = $this->modifiers($item, $req['modifiers'] ?? [], $prefix);
            $bundle = $item->isBundle() ? $this->bundle($item, $req['bundle'] ?? [], $prefix) : [];
            foreach ($bundle as $choice) {
                $unit = $unit->plus($choice['extra_price']);
            }

            $unitWithMods = $unit;
            foreach ($modifiers as $m) {
                $unitWithMods = $unitWithMods->plus(BigDecimal::of($m['price'])->multipliedBy($m['qty']));
            }

            $cartLines[] = [
                'id' => $lineId,
                'item_id' => $item->id,
                'category_id' => $item->category_id,
                'brand_id' => $item->brand_id,
                'unit_price' => (string) $unitWithMods,
                'qty' => (string) $req['qty'],
            ];
            $calcLines[$lineId] = [
                'id' => $lineId,
                'unit_price' => (string) $unit,
                'qty' => (string) $req['qty'],
                'modifiers' => array_map(fn ($m) => ['price' => $m['price'], 'qty' => (string) $m['qty']], $modifiers),
                'discounts' => [],
            ];
            $described[$lineId] = [
                'id' => $lineId,
                'item_id' => $item->id,
                'name' => $item->name,
                'variant' => $variant ? ['id' => $variant->id, 'name' => $variant->name] : null,
                'unit_price' => (string) $unit,
                'qty' => (string) $req['qty'],
                'modifiers' => $modifiers,
                'bundle' => array_map(fn ($c) => ['group' => $c['group'], 'item_id' => $c['item_id'], 'name' => $c['name'], 'extra_price' => (string) $c['extra_price']], $bundle),
                'kitchen_station_id' => $item->kitchen_station_id,
                'note' => $req['note'] ?? null,
            ];
            $manual = $req['discounts'] ?? [];
            $calcLines[$lineId]['manual'] = array_map(fn ($d) => ['type' => $d['type'], 'value' => (string) $d['value'], 'source' => 'manual'], $manual);
        }

        $promos = Promotion::query()
            ->with(['targets', 'outlets'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $outlet->brand_id))
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->get()
            ->map(fn (Promotion $p) => $p->toEngineArray())
            ->all();

        try {
            return $this->price($outlet, $channel, $local, $at, $request, $cartLines, $calcLines, $described, $promos);
        } catch (\InvalidArgumentException $e) {
            // Input lolos validasi format tetapi ditolak kalkulator (mis. diskon tidak masuk akal).
            throw ValidationException::withMessages(['lines' => 'Pesanan tidak dapat dihitung: '.$e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  list<array<string, mixed>>  $cartLines
     * @param  array<string, array<string, mixed>>  $calcLines
     * @param  array<string, array<string, mixed>>  $described
     * @param  list<array<string, mixed>>  $promos
     * @return array<string, mixed>
     */
    private function price(Outlet $outlet, SalesChannel $channel, CarbonImmutable $local, CarbonImmutable $at, array $request, array $cartLines, array $calcLines, array $described, array $promos): array
    {
        $promoResult = $this->promotions->apply(['lines' => $cartLines], [
            'outlet_id' => $outlet->id,
            'channel_code' => $channel->code,
            'local_time' => $local->format('Y-m-d H:i'),
            'timezone' => $outlet->timezone,
            'payment_method' => $request['payment_method'] ?? null,
            'codes' => $request['promo_codes'] ?? [],
        ], $promos);

        foreach ($calcLines as $id => &$line) {
            $line['discounts'] = array_merge($promoResult['line_discounts'][$id] ?? [], $line['manual']);
            unset($line['manual']);
        }
        unset($line);

        $orderDiscounts = array_merge(
            $promoResult['order_discounts'],
            array_map(fn ($d) => ['type' => $d['type'], 'value' => (string) $d['value'], 'source' => 'manual'], $request['order_discounts'] ?? []),
        );

        $result = $this->calculator->calculate([
            'config' => [
                'tax_rate' => (string) $outlet->tax_rate,
                'tax_inclusive' => $outlet->tax_inclusive,
                'tax_on_service_charge' => $outlet->tax_on_service_charge,
                'service_charge_rate' => (string) $outlet->service_charge_rate,
                'service_charge_applies' => $channel->service_charge_applies,
                'rounding_unit' => $outlet->rounding_unit,
                'rounding_mode' => $outlet->rounding_mode,
            ],
            'lines' => array_values($calcLines),
            'order_discounts' => $orderDiscounts,
        ]);

        $lines = [];
        foreach ($result['lines'] as $row) {
            $lines[] = $described[$row['id']] + $row + ['discounts' => $calcLines[$row['id']]['discounts']];
        }

        return [
            'outlet_id' => $outlet->id,
            'channel' => ['id' => $channel->id, 'code' => $channel->code, 'name' => $channel->name],
            'priced_at' => $at->toIso8601String(),
            'tax' => ['name' => $outlet->tax_name, 'rate' => (string) $outlet->tax_rate, 'inclusive' => $outlet->tax_inclusive],
            'lines' => $lines,
            'totals' => $result['totals'],
            'discounts' => $result['discounts'],
            'promotions' => $promoResult['applied'],
        ];
    }

    private function variant(Item $item, ?string $variantId, string $prefix): ?ItemVariant
    {
        $variants = $item->variants->where('is_active', true);
        if ($variants->isEmpty()) {
            if ($variantId !== null) {
                throw ValidationException::withMessages(["{$prefix}.variant_id" => "{$item->name} tidak memiliki varian."]);
            }

            return null;
        }

        $variant = $variantId === null ? null : $variants->firstWhere('id', $variantId);
        if ($variant === null) {
            throw ValidationException::withMessages(["{$prefix}.variant_id" => "Pilih varian untuk {$item->name}."]);
        }

        return $variant;
    }

    /**
     * @param  list<array{id: string, qty?: int}>  $requested
     * @return list<array{id: string, group_id: string, name: string, price: string, qty: int}>
     */
    private function modifiers(Item $item, array $requested, string $prefix): array
    {
        $chosen = [];
        foreach ($requested as $r) {
            $chosen[$r['id']] = ($chosen[$r['id']] ?? 0) + (int) ($r['qty'] ?? 1);
        }

        $result = [];
        $seen = [];
        foreach ($item->modifierGroups->where('is_active', true) as $group) {
            $count = 0;
            foreach ($group->modifiers->where('is_active', true) as $modifier) {
                /** @var Modifier $modifier */
                if (! isset($chosen[$modifier->id])) {
                    continue;
                }
                $qty = $chosen[$modifier->id];
                $count += $qty;
                $seen[] = $modifier->id;
                $result[] = ['id' => $modifier->id, 'group_id' => $group->id, 'name' => $modifier->name, 'price' => (string) $modifier->price, 'qty' => $qty];
            }

            if ($count < $group->min_select) {
                throw ValidationException::withMessages(["{$prefix}.modifiers" => "Pilih minimal {$group->min_select} pada {$group->name}."]);
            }
            if ($group->max_select > 0 && $count > $group->max_select) {
                throw ValidationException::withMessages(["{$prefix}.modifiers" => "Pilihan {$group->name} maksimal {$group->max_select}."]);
            }
        }

        if (array_diff(array_keys($chosen), $seen) !== []) {
            throw ValidationException::withMessages(["{$prefix}.modifiers" => "Ada pilihan tambahan yang tidak berlaku untuk {$item->name}."]);
        }

        return $result;
    }

    /**
     * @param  list<array{group_id: string, options: list<array{option_id: string}>}>  $requested
     * @return list<array{group: string, item_id: string, name: string, extra_price: BigDecimal}>
     */
    private function bundle(Item $item, array $requested, string $prefix): array
    {
        $byGroup = collect($requested)->keyBy('group_id');
        $result = [];

        foreach ($item->bundleGroups as $group) {
            $optionIds = collect($byGroup->get($group->id)['options'] ?? [])->pluck('option_id')->all();
            if ($optionIds === []) {
                $optionIds = $group->options->where('is_default', true)->pluck('id')->all();
            }
            $count = count($optionIds);
            if ($count < $group->min_select || $count > $group->max_select) {
                throw ValidationException::withMessages(["{$prefix}.bundle" => "Pilih {$group->min_select}–{$group->max_select} untuk {$group->name}."]);
            }
            foreach ($optionIds as $optionId) {
                $option = $group->options->firstWhere('id', $optionId);
                if ($option === null) {
                    throw ValidationException::withMessages(["{$prefix}.bundle" => "Pilihan paket tidak dikenal pada {$group->name}."]);
                }
                if ($option->item === null || ! $option->item->is_active) {
                    throw ValidationException::withMessages(["{$prefix}.bundle" => "Pilihan pada {$group->name} sedang tidak tersedia."]);
                }
                $result[] = [
                    'group' => $group->name,
                    'item_id' => $option->item_id,
                    'name' => $option->item->name,
                    'extra_price' => BigDecimal::of((string) $option->extra_price),
                ];
            }
        }

        $unknown = $byGroup->keys()->diff($item->bundleGroups->pluck('id'));
        if ($unknown->isNotEmpty()) {
            throw ValidationException::withMessages(["{$prefix}.bundle" => 'Grup paket tidak dikenal.']);
        }

        return $result;
    }
}
