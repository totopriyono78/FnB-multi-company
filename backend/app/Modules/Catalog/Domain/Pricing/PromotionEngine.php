<?php

namespace App\Modules\Catalog\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Penerapan promo otomatis/berkode (FR-MENU-11, FR-MENU-12, FR-MENU-13) dengan aturan BR-18:
 * promo tidak ditumpuk kecuali stackable; dipilih kombinasi paling menguntungkan pelanggan.
 *
 * Fungsi murni: dipakai server dan (versi Dart) POS offline. Kasus uji: shared/fixtures/promotions.
 */
final class PromotionEngine
{
    /**
     * @param  array{lines: list<array<string, mixed>>}  $cart  baris: id, item_id, category_id, brand_id, unit_price (termasuk modifier), qty
     * @param  array<string, mixed>  $context  outlet_id, channel_code, local_time (Y-m-d H:i), timezone, payment_method, codes
     * @param  list<array<string, mixed>>  $promotions  bentuk Promotion::toEngineArray()
     * @return array{applied: list<array{id: string, name: string, amount: string}>, line_discounts: array<string, list<array{type: string, value: string, source: string}>>, order_discounts: list<array{type: string, value: string, source: string}>}
     */
    public function apply(array $cart, array $context, array $promotions): array
    {
        $lines = array_values($cart['lines'] ?? []);
        $gross = [];
        $subtotal = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = BigDecimal::of((string) $line['unit_price'])->multipliedBy((string) $line['qty']);
            $gross[(string) $line['id']] = $amount;
            $subtotal = $subtotal->plus($amount);
        }

        $timezone = (string) ($context['timezone'] ?? 'Asia/Jakarta');
        try {
            $local = CarbonImmutable::createFromFormat('Y-m-d H:i', (string) $context['local_time'], $timezone);
        } catch (InvalidFormatException) {
            $local = null;
        }
        if ($local === null) {
            throw new \InvalidArgumentException('local_time harus berformat Y-m-d H:i.');
        }
        $codes = array_map(fn ($c) => mb_strtoupper((string) $c), $context['codes'] ?? []);

        $candidates = [];
        foreach ($promotions as $promo) {
            if (! $this->eligible($promo, $context, $local, $codes, $subtotal)) {
                continue;
            }
            $eligibleLines = array_values(array_filter($lines, fn ($l) => $this->lineMatches($promo, $l)));
            if ($eligibleLines === []) {
                continue;
            }
            $promo['_lines'] = $eligibleLines;
            if ($this->evaluate($promo, $gross, [])['total']->isPositive()) {
                $candidates[] = $promo;
            }
        }

        usort($candidates, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0) ?: strcmp((string) $a['id'], (string) $b['id']));

        $options = [];
        foreach ($candidates as $promo) {
            if (! ($promo['stackable'] ?? false)) {
                $options[] = [$promo];
            }
        }
        $stackables = array_values(array_filter($candidates, fn ($p) => (bool) ($p['stackable'] ?? false)));
        if ($stackables !== []) {
            $options[] = $stackables;
        }

        $best = null;
        foreach ($options as $option) {
            $result = $this->combine($option, $gross);
            if ($best === null || $result['total']->isGreaterThan($best['total'])) {
                $best = $result;
            }
        }

        if ($best === null) {
            return ['applied' => [], 'line_discounts' => [], 'order_discounts' => []];
        }

        return [
            'applied' => $best['applied'],
            'line_discounts' => $best['line_discounts'],
            'order_discounts' => $best['order_discounts'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $promos
     * @param  array<string, BigDecimal>  $gross
     * @return array{total: BigDecimal, applied: list<array{id: string, name: string, amount: string}>, line_discounts: array<string, list<array{type: string, value: string, source: string}>>, order_discounts: list<array{type: string, value: string, source: string}>}
     */
    private function combine(array $promos, array $gross): array
    {
        // Promo item dulu, lalu promo transaksi atas sisa (SRS §8.1 langkah 2–3).
        usort($promos, fn ($a, $b) => ($a['scope'] === 'order' ? 1 : 0) <=> ($b['scope'] === 'order' ? 1 : 0));

        $remaining = $gross;
        $total = BigDecimal::zero();
        $applied = [];
        $lineDiscounts = [];
        $orderDiscounts = [];

        foreach ($promos as $promo) {
            $result = $this->evaluate($promo, $gross, $remaining);
            if (! $result['total']->isPositive()) {
                continue;
            }
            $source = 'promo:'.$promo['id'];
            $applied[] = ['id' => (string) $promo['id'], 'name' => (string) $promo['name'], 'amount' => (string) $result['total']];
            $total = $total->plus($result['total']);

            if ($promo['scope'] === 'order') {
                $orderDiscounts[] = ['type' => 'amount', 'value' => (string) $result['total'], 'source' => $source];
            }
            foreach ($result['lines'] as $lineId => $amount) {
                $remaining[$lineId] = $remaining[$lineId]->minus($amount);
                if ($promo['scope'] !== 'order' && $amount->isPositive()) {
                    $lineDiscounts[$lineId][] = ['type' => 'amount', 'value' => (string) $amount, 'source' => $source];
                }
            }
        }

        return ['total' => $total, 'applied' => $applied, 'line_discounts' => $lineDiscounts, 'order_discounts' => $orderDiscounts];
    }

    /**
     * Nilai potongan satu promo, dibulatkan 2 desimal dan dialokasikan ke baris.
     *
     * @param  array<string, mixed>  $promo
     * @param  array<string, BigDecimal>  $gross
     * @param  array<string, BigDecimal>  $remaining  sisa nilai baris; kosong = gunakan gross
     * @return array{total: BigDecimal, lines: array<string, BigDecimal>}
     */
    private function evaluate(array $promo, array $gross, array $remaining): array
    {
        $value = BigDecimal::of((string) $promo['value']);
        $exact = [];
        $base = fn (string $id) => $remaining[$id] ?? $gross[$id];

        foreach ($promo['_lines'] as $line) {
            $id = (string) $line['id'];
            $exact[$id] = BigDecimal::zero();
        }

        switch ($promo['type']) {
            case 'percent':
                foreach ($promo['_lines'] as $line) {
                    $id = (string) $line['id'];
                    $exact[$id] = $base($id)->multipliedBy($value)->dividedBy(100, 20, RoundingMode::HALF_UP);
                }
                break;

            case 'amount':
                if ($promo['scope'] === 'order') {
                    $pool = BigDecimal::zero();
                    foreach (array_keys($exact) as $id) {
                        $pool = $pool->plus($base((string) $id));
                    }
                    $cut = $value->isGreaterThan($pool) ? $pool : $value;
                    foreach (array_keys($exact) as $id) {
                        $exact[$id] = $pool->isZero()
                            ? BigDecimal::zero()
                            : $cut->multipliedBy($base((string) $id))->dividedBy($pool, 20, RoundingMode::HALF_UP);
                    }
                } else {
                    foreach ($promo['_lines'] as $line) {
                        $id = (string) $line['id'];
                        $cut = $value->multipliedBy((string) $line['qty']);
                        $exact[$id] = $cut->isGreaterThan($base($id)) ? $base($id) : $cut;
                    }
                }
                break;

            case 'special_price':
                foreach ($promo['_lines'] as $line) {
                    $id = (string) $line['id'];
                    $unit = BigDecimal::of((string) $line['unit_price']);
                    if ($unit->isGreaterThan($value)) {
                        $cut = $unit->minus($value)->multipliedBy((string) $line['qty']);
                        $exact[$id] = $cut->isGreaterThan($base($id)) ? $base($id) : $cut;
                    }
                }
                break;

            case 'buy_x_get_y':
                $buy = (int) ($promo['buy_qty'] ?? 0);
                $get = (int) ($promo['get_qty'] ?? 0);
                if ($buy < 1 || $get < 1) {
                    break;
                }
                // Unit diurutkan dari harga termahal; tiap kelompok (buy + get) unit, `get` unit termurahnya gratis.
                // Dihitung per kelompok harga (bukan per unit) agar qty besar tidak memakan memori/CPU.
                $groups = [];
                foreach ($promo['_lines'] as $order => $line) {
                    $qty = BigDecimal::of((string) $line['qty']);
                    if (! $qty->isEqualTo($qty->toScale(0, RoundingMode::DOWN))) {
                        continue; // qty pecahan (menu berat) tidak ikut beli-X-gratis-Y
                    }
                    $groups[] = ['id' => (string) $line['id'], 'price' => BigDecimal::of((string) $line['unit_price']), 'order' => $order, 'count' => $qty->toInt()];
                }
                usort($groups, fn ($a, $b) => $b['price']->compareTo($a['price']) ?: $a['order'] <=> $b['order']);
                $chunk = $buy + $get;
                $totalUnits = array_sum(array_column($groups, 'count'));
                $covered = intdiv($totalUnits, $chunk) * $chunk;
                // Jumlah posisi gratis di rentang [0, n) dari urutan unit.
                $freeBefore = function (int $n) use ($chunk, $buy, $get, $covered): int {
                    $n = min($n, $covered);

                    return intdiv($n, $chunk) * $get + max(0, ($n % $chunk) - $buy);
                };
                $position = 0;
                foreach ($groups as $group) {
                    $free = $freeBefore($position + $group['count']) - $freeBefore($position);
                    $position += $group['count'];
                    if ($free > 0) {
                        $exact[$group['id']] = $exact[$group['id']]->plus($group['price']->multipliedBy($free));
                    }
                }
                foreach ($exact as $id => $amount) {
                    if ($amount->isGreaterThan($base((string) $id))) {
                        $exact[$id] = $base((string) $id);
                    }
                }
                break;
        }

        $total = array_reduce($exact, fn (BigDecimal $c, BigDecimal $a) => $c->plus($a), BigDecimal::zero())
            ->toScale(2, RoundingMode::HALF_UP);
        if (($promo['max_discount'] ?? null) !== null) {
            $cap = BigDecimal::of((string) $promo['max_discount']);
            $total = $total->isGreaterThan($cap) ? $cap->toScale(2) : $total;
        }

        $lines = $total->isZero() ? array_map(fn () => BigDecimal::zero()->toScale(2), $exact) : Allocation::distribute($exact, $total);

        return ['total' => $total, 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $promo
     * @param  array<string, mixed>  $context
     * @param  list<string>  $codes
     */
    private function eligible(array $promo, array $context, CarbonImmutable $local, array $codes, BigDecimal $subtotal): bool
    {
        $now = $local->utc();
        if ($now->lessThan(CarbonImmutable::parse((string) $promo['starts_at']))) {
            return false;
        }
        if (($promo['ends_at'] ?? null) !== null && ! $now->lessThan(CarbonImmutable::parse((string) $promo['ends_at']))) {
            return false;
        }
        if (($promo['quota_remaining'] ?? null) !== null && (int) $promo['quota_remaining'] <= 0) {
            return false;
        }
        if (! ($promo['auto_apply'] ?? true)) {
            $code = mb_strtoupper((string) ($promo['code'] ?? ''));
            if ($code === '' || ! in_array($code, $codes, true)) {
                return false;
            }
        }
        if (($promo['outlet_ids'] ?? []) !== [] && ! in_array($context['outlet_id'] ?? null, $promo['outlet_ids'], true)) {
            return false;
        }
        if (($promo['channel_codes'] ?? null) !== null && ! in_array($context['channel_code'] ?? null, $promo['channel_codes'], true)) {
            return false;
        }
        if (($promo['payment_methods'] ?? null) !== null && ! in_array($context['payment_method'] ?? null, $promo['payment_methods'], true)) {
            return false;
        }
        if (($promo['min_purchase'] ?? null) !== null && $subtotal->isLessThan((string) $promo['min_purchase'])) {
            return false;
        }

        $hasTime = ($promo['time_start'] ?? null) !== null || ($promo['time_end'] ?? null) !== null;
        $days = $promo['days_of_week'] ?? null;
        if ($hasTime || ($days !== null && $days !== [])) {
            return ScheduleMatcher::inWindow($days, $promo['time_start'] ?? null, $promo['time_end'] ?? null, $local);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $promo
     * @param  array<string, mixed>  $line
     */
    private function lineMatches(array $promo, array $line): bool
    {
        if (($promo['brand_id'] ?? null) !== null && ($line['brand_id'] ?? null) !== $promo['brand_id']) {
            return false;
        }

        $items = $promo['item_ids'] ?? [];
        $categories = $promo['category_ids'] ?? [];
        if ($items === [] && $categories === []) {
            return $promo['scope'] === 'order';
        }

        return in_array($line['item_id'] ?? null, $items, true) || in_array($line['category_id'] ?? null, $categories, true);
    }
}
