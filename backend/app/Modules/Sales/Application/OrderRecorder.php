<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Catalog\Application\PriceResolver;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Catalog\Domain\Models\Promotion;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Catalog\Domain\Pricing\PricingCalculator;
use App\Modules\Catalog\Domain\Pricing\PromotionEngine;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\PaymentIntentService;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Domain\Models\PaymentIntent;
use App\Modules\Sales\Domain\Events\OrderCompleted;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderDiscount;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\OrderPayment;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Menyimpan transaksi yang dibuat POS (FR-POS-10..24, FR-PAY-03..08, ADR 0004).
 *
 * Penjualan yang sudah terjadi di outlet tidak ditolak hanya karena data master berubah (harga, promo, kuota);
 * perbedaan tersebut ditandai `flags` untuk ditinjau. Penolakan hanya untuk data yang tidak konsisten
 * (total salah hitung, pembayaran kurang/ganda) atau aksi tanpa kewenangan.
 */
class OrderRecorder
{
    public const TOTAL_KEYS = ['subtotal', 'item_discount', 'order_discount', 'service_charge', 'tax', 'rounding', 'total'];

    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly PriceResolver $prices,
        private readonly PricingCalculator $calculator,
        private readonly PromotionEngine $promotions,
        private readonly PaymentMethods $methods,
        private readonly PaymentIntentService $intents,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'];
        $discount = [
            'type' => ['required', Rule::in(['percent', 'amount'])],
            'value' => $money,
            'source' => ['required', 'string', 'max:60', 'regex:/^(manual|promo:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i'],
            'reason' => ['nullable', 'string', 'max:200'],
        ];

        $rules = [
            'id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'cashier_id' => ['required', 'uuid'],
            'receipt_no' => ['required', 'string', 'max:40'],
            'queue_no' => ['nullable', 'integer', 'between:1,99999'],
            'channel_code' => ['required', 'string', 'max:30'],
            'table_label' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
            'status' => ['required', Rule::in([Order::PAID, Order::VOIDED])],
            'created_at' => ['required', 'date'],
            'completed_at' => ['nullable', 'date'],
            'promo_codes' => ['nullable', 'array', 'max:5'],
            'promo_codes.*' => ['string', 'max:40'],
            'pricing' => ['required', 'array'],
            'pricing.tax_name' => ['required', 'string', 'max:20'],
            'pricing.tax_rate' => ['required', 'numeric', 'between:0,100'],
            'pricing.tax_inclusive' => ['required', 'boolean'],
            'pricing.tax_on_service_charge' => ['required', 'boolean'],
            'pricing.service_charge_rate' => ['required', 'numeric', 'between:0,100'],
            'pricing.service_charge_applies' => ['required', 'boolean'],
            'pricing.rounding_unit' => ['required', 'integer', Rule::in([0, 50, 100, 500, 1000])],
            'pricing.rounding_mode' => ['required', Rule::in(Outlet::ROUNDING_MODES)],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'uuid', 'distinct'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.name' => ['nullable', 'string', 'max:100'],
            'lines.*.variant_name' => ['nullable', 'string', 'max:40'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999', 'decimal:0,3'],
            'lines.*.unit_price' => $money,
            'lines.*.price_override' => ['sometimes', 'boolean'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
            'lines.*.sent_to_kitchen_at' => ['nullable', 'date'],
            'lines.*.modifiers' => ['nullable', 'array', 'max:30'],
            'lines.*.modifiers.*.id' => ['required', 'uuid'],
            'lines.*.modifiers.*.name' => ['nullable', 'string', 'max:60'],
            'lines.*.modifiers.*.price' => $money,
            'lines.*.modifiers.*.qty' => ['required', 'integer', 'between:1,99'],
            'lines.*.bundle' => ['nullable', 'array', 'max:20'],
            'lines.*.bundle.*.option_id' => ['required', 'uuid'],
            'lines.*.bundle.*.name' => ['nullable', 'string', 'max:100'],
            'lines.*.bundle.*.extra_price' => $money,
            'lines.*.discounts' => ['nullable', 'array', 'max:5'],
            'order_discounts' => ['nullable', 'array', 'max:5'],
            'totals' => ['required', 'array'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.id' => ['required', 'uuid', 'distinct'],
            'payments.*.method' => ['required', 'string', 'max:20'],
            'payments.*.amount' => [...$money, 'gt:0'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],
            'payments.*.payment_intent_id' => ['nullable', 'uuid'],
            'payments.*.created_at' => ['required', 'date'],
            'authorizations' => ['nullable', 'array'],
            'authorizations.discount' => ['nullable', 'array'],
            'authorizations.price_override' => ['nullable', 'array'],
            'void' => ['required_if:status,voided', 'nullable', 'array'],
            'void.reason' => ['required_with:void', 'string', 'max:300'],
            'void.voided_by' => ['required_with:void', 'uuid'],
            'void.authorization' => ['nullable', 'array'],
        ];
        foreach ($discount as $key => $rule) {
            $rules["lines.*.discounts.*.{$key}"] = $rule;
            $rules["order_discounts.*.{$key}"] = $rule;
        }
        foreach (self::TOTAL_KEYS as $key) {
            $rules["totals.{$key}"] = ['required', 'numeric', 'decimal:0,2'];
        }

        return $rules;
    }

    /** @param  array<string, mixed>  $input */
    public function record(Device $device, array $input): Order
    {
        $validator = Validator::make($input, self::rules());
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data transaksi tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $createdAt = $this->shifts->time($data['created_at'], 'created_at');
        $completedAt = isset($data['completed_at']) ? $this->shifts->time($data['completed_at'], 'completed_at') : $createdAt;

        return DB::transaction(function () use ($device, $outlet, $data, $createdAt, $completedAt): Order {
            /** @var Shift|null $shift */
            $shift = Shift::query()->whereKey($data['shift_id'])->lockForUpdate()->first();
            $shift = $this->shifts->assertShiftOnDevice($shift, $device);
            if ($shift->status !== Shift::OPEN) {
                throw new SalesException('SHIFT_CLOSED', 'Shift sudah ditutup; transaksi tidak dapat ditambahkan.', 409, field: 'shift_id');
            }
            $this->shifts->assertWithinShift($shift, $createdAt);

            $cashier = $this->auth->staff($data['cashier_id'], $outlet, 'pos.transact');
            $businessDate = $shift->business_date;

            if (! ReceiptNumber::matches($data['receipt_no'], $outlet, $device, $businessDate)) {
                throw new SalesException('INVALID_RECEIPT_NO', 'Format nomor struk harus {KODE_OUTLET}-{KODE_PERANGKAT}-{YYMMDD hari bisnis}-{URUT}.', 422, field: 'receipt_no');
            }
            if (Order::query()->where('receipt_no', $data['receipt_no'])->where('business_date', $businessDate->format('Y-m-d'))->exists()) {
                throw new SalesException('DUPLICATE_RECEIPT_NO', 'Nomor struk sudah dipakai transaksi lain.', 409, field: 'receipt_no');
            }

            $channel = SalesChannel::query()->where('code', $data['channel_code'])->first()
                ?? throw new SalesException('CHANNEL_UNKNOWN', 'Channel penjualan tidak dikenal.', 422, field: 'channel_code');

            $flags = ['offline_authorization' => false];
            // Perbedaan data master hanya sah bila perubahan terjadi SETELAH perangkat terakhir menarik data
            // (waktu server), bukan setelah waktu transaksi dari perangkat yang bisa dimundurkan.
            $knownAt = $shift->master_pulled_at ?? $device->master_pulled_at;
            $lines = $this->resolveLines($outlet, $channel, $data['lines'], $knownAt);
            $needsOverride = false;
            foreach ($lines as $line) {
                if ($line['price_override']) {
                    $needsOverride = true;
                } elseif ($line['price_mismatch'] && $line['below_catalog'] && ! $line['catalog_changed']) {
                    $this->requirePulled($knownAt);
                    // Harga lebih rendah dari katalog yang tidak berubah sejak transaksi = ubah harga manual.
                    $needsOverride = true;
                } elseif ($line['price_mismatch']) {
                    $flags['price_mismatch'] = true;
                }
            }

            // Ubah harga manual wajib otorisasi (FR-POS-14).
            $overrideBy = null;
            if ($needsOverride) {
                $flags['price_override'] = true;
                if (! $this->auth->selfAuthorized($cashier, 'pos.price_override', $outlet)) {
                    [$supervisor, $offline] = $this->auth->verify($data['authorizations']['price_override'] ?? null, 'price_override', $device, $createdAt, 'authorizations.price_override', $data['id'], ['reference_id' => $data['id']]);
                    $overrideBy = $supervisor->id;
                    $flags['offline_authorization'] = $offline;
                }
            }

            // Hitung ulang total (BR-05): promo dulu lalu diskon manual, sesuai urutan di QuoteService & POS.
            $calcLines = [];
            foreach ($lines as $line) {
                $calcLines[] = [
                    'id' => $line['id'],
                    'unit_price' => $line['unit_price'],
                    'qty' => $line['qty'],
                    'modifiers' => array_map(fn ($m) => ['price' => $m['price'], 'qty' => (string) $m['qty']], $line['modifiers']),
                    'discounts' => $this->orderedDiscounts($line['discounts']),
                ];
            }
            $orderDiscounts = $this->orderedDiscounts($data['order_discounts'] ?? []);
            $config = [
                'tax_rate' => (string) $data['pricing']['tax_rate'],
                'tax_inclusive' => (bool) $data['pricing']['tax_inclusive'],
                'tax_on_service_charge' => (bool) $data['pricing']['tax_on_service_charge'],
                'service_charge_rate' => (string) $data['pricing']['service_charge_rate'],
                'service_charge_applies' => (bool) $data['pricing']['service_charge_applies'],
                'rounding_unit' => (int) $data['pricing']['rounding_unit'],
                'rounding_mode' => (string) $data['pricing']['rounding_mode'],
            ];

            try {
                $result = $this->calculator->calculate(['config' => $config, 'lines' => $calcLines, 'order_discounts' => $orderDiscounts]);
            } catch (InvalidArgumentException $e) {
                throw new SalesException('INVALID_ORDER', 'Transaksi tidak dapat dihitung: '.$e->getMessage(), 422);
            }
            $totals = $result['totals'];
            foreach (self::TOTAL_KEYS as $key) {
                if (! BigDecimal::of((string) $data['totals'][$key])->isEqualTo(BigDecimal::of($totals[$key]))) {
                    throw new SalesException('TOTAL_MISMATCH', 'Total transaksi tidak sesuai perhitungan server.', 422, field: "totals.{$key}", details: [
                        'expected' => array_intersect_key($totals, array_flip(self::TOTAL_KEYS)),
                    ]);
                }
            }

            if ($this->configDiffers($outlet, $channel, $config, (string) $data['pricing']['tax_name'])) {
                // Hanya sah bila pengaturan berubah setelah transaksi terjadi (perangkat memakai data lama).
                $this->requirePulled($knownAt);
                $changedAfter = ($outlet->updated_at !== null && $outlet->updated_at->greaterThan($knownAt))
                    || ($channel->updated_at !== null && $channel->updated_at->greaterThan($knownAt));
                if (! $changedAfter) {
                    throw new SalesException('CONFIG_MISMATCH', 'Pengaturan pajak, service charge, atau pembulatan tidak sesuai pengaturan outlet.', 422, field: 'pricing');
                }
                $flags['config_mismatch'] = true;
            }

            // Promo: hitung ulang dengan mesin promo; beda hasil → ditandai, bukan ditolak.
            $firstMethod = $data['payments'][0]['method'] ?? null;
            $promoCheck = $this->promotionsMatch($outlet, $channel, $createdAt, $knownAt, $lines, $orderDiscounts, $data['promo_codes'] ?? [], $firstMethod);
            if ($promoCheck['untrusted'] !== []) {
                $this->requirePulled($knownAt);
            }
            if (! $promoCheck['match']) {
                $flags['promo_mismatch'] = true;
            }
            // Klaim promo yang tidak dapat dijelaskan diperlakukan sebagai diskon manual untuk batas role.
            $asManual = array_merge(['manual'], $promoCheck['untrusted']);

            // Diskon manual & batas role (BR-14).
            $discountBy = null;
            $manualAmount = BigDecimal::zero();
            foreach ($result['discounts'] as $row) {
                if (in_array($row['source'], $asManual, true)) {
                    $manualAmount = $manualAmount->plus($row['amount']);
                }
            }
            $manualPercent = $this->manualPercent($lines, $orderDiscounts, $manualAmount, BigDecimal::of($totals['subtotal']), $asManual);
            if ($manualPercent !== null) {
                $allowed = $this->auth->selfAuthorized($cashier, 'pos.discount', $outlet)
                    && BigDecimal::of($this->auth->maxDiscount($cashier))->isGreaterThanOrEqualTo($manualPercent);
                if (! $allowed) {
                    [$supervisor, $offline] = $this->auth->verify($data['authorizations']['discount'] ?? null, 'discount', $device, $createdAt, 'authorizations.discount', $data['id'], [
                        'reference_id' => $data['id'],
                        'discount_percent' => (string) $manualPercent->toScale(2, RoundingMode::DOWN),
                    ]);
                    if (BigDecimal::of($this->auth->maxDiscount($supervisor))->isLessThan($manualPercent)) {
                        throw new SalesException('DISCOUNT_LIMIT_EXCEEDED', 'Diskon melebihi batas yang boleh disetujui pemberi otorisasi.', 403, field: 'authorizations.discount', details: ['discount_percent' => (string) $manualPercent->toScale(2)]);
                    }
                    $discountBy = $supervisor->id;
                    $flags['offline_authorization'] = $flags['offline_authorization'] || $offline;
                }
            }

            $isVoided = $data['status'] === Order::VOIDED;
            $void = null;
            if ($isVoided) {
                $void = $this->voidBeforePayment($device, $outlet, $data, $createdAt, $flags);
            }

            $total = BigDecimal::of($totals['total']);
            $payments = $isVoided ? [] : $this->payments($device, $outlet, $data, $total, $flags);

            $order = new Order;
            $order->forceFill([
                'id' => $data['id'],
                'business_date' => $businessDate->format('Y-m-d'),
                'company_id' => $device->company_id,
                'outlet_id' => $outlet->id,
                'device_id' => $device->id,
                'shift_id' => $shift->id,
                'cashier_id' => $cashier->id,
                'receipt_no' => $data['receipt_no'],
                'queue_no' => $data['queue_no'] ?? null,
                'sales_channel_id' => $channel->id,
                'channel_code' => $channel->code,
                'table_label' => $data['table_label'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => $data['status'],
                'subtotal' => $totals['subtotal'],
                'item_discount' => $totals['item_discount'],
                'order_discount' => $totals['order_discount'],
                'service_charge' => $totals['service_charge'],
                'tax' => $totals['tax'],
                'rounding' => $totals['rounding'],
                'total' => $totals['total'],
                'paid_total' => (string) array_reduce($payments, fn (BigDecimal $c, array $p) => $c->plus($p['amount']), BigDecimal::zero())->toScale(2),
                'change_amount' => (string) array_reduce($payments, fn (BigDecimal $c, array $p) => $c->plus($p['change_amount']), BigDecimal::zero())->toScale(2),
                'tax_name' => $data['pricing']['tax_name'],
                'pricing' => $config + ['tax_name' => $data['pricing']['tax_name']],
                'totals' => $totals,
                'promo_codes' => array_values(array_unique(array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $data['promo_codes'] ?? []))) ?: null,
                'flags' => array_keys(array_filter($flags)),
                'device_created_at' => $createdAt,
                'completed_at' => $isVoided ? null : $completedAt,
                'server_received_at' => now(),
                'voided_at' => $isVoided ? $completedAt : null,
                'voided_by' => $void['voided_by'] ?? null,
                'void_authorized_by' => $void['authorized_by'] ?? null,
                'void_reason' => $void['reason'] ?? null,
                'void_business_date' => $isVoided ? $businessDate->format('Y-m-d') : null,
            ])->save();

            $this->storeItems($order, $lines, $result['lines'], $void);
            $this->storeDiscounts($order, $lines, $orderDiscounts, BigDecimal::of($totals['subtotal'])->minus($totals['item_discount']), $cashier, $discountBy);
            $this->storePayments($order, $payments);

            if (! $isVoided) {
                $this->countPromotionUse($order, $lines, $orderDiscounts);
            }

            if ($order->flags !== []) {
                $this->audit->log('order.flagged', $order, new: ['flags' => $order->flags, 'receipt_no' => $order->receipt_no], userId: $cashier->id);
            }
            if ($overrideBy !== null) {
                $this->audit->log('order.price_override', $order, authorizedBy: $overrideBy, userId: $cashier->id);
            }
            if ($discountBy !== null) {
                $this->audit->log('order.discount_authorized', $order, new: ['discount_percent' => (string) $manualPercent?->toScale(2)], authorizedBy: $discountBy, userId: $cashier->id);
            }
            if ($isVoided) {
                $this->audit->log('order.cancelled_before_payment', $order, reason: $order->void_reason, authorizedBy: $order->void_authorized_by, userId: $order->voided_by);
            }

            DB::afterCommit(fn () => OrderCompleted::dispatch($order->company_id, $order->id, $order->business_date->format('Y-m-d'), $order->status));

            return $order;
        });
    }

    /**
     * Cocokkan baris dengan katalog server (tanpa menolak perubahan harga setelah perangkat offline).
     *
     * @param  list<array<string, mixed>>  $requested
     * @return list<array<string, mixed>>
     */
    private function resolveLines(Outlet $outlet, SalesChannel $channel, array $requested, ?CarbonImmutable $knownAt): array
    {
        /** @var Collection<string, Item> $items */
        $items = Item::withTrashed()
            ->with(['variants', 'prices', 'bundleGroups.options'])
            ->whereIn('id', collect($requested)->pluck('item_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $modifierIds = [];
        foreach ($requested as $req) {
            foreach ($req['modifiers'] ?? [] as $m) {
                $modifierIds[$m['id']] = true;
            }
        }
        $modifierIds = array_keys($modifierIds);
        /** @var Collection<string, Modifier> $modifiers */
        $modifiers = Modifier::query()->whereIn('id', $modifierIds)->get()->keyBy('id');
        // Menu yang harganya berubah setelah transaksi: harga lama dari perangkat masih wajar.
        $repriced = $knownAt === null ? [] : ItemPriceHistory::query()
            ->whereIn('item_id', $items->keys()->all())
            ->where('created_at', '>', $knownAt)
            ->whereNotNull('old_price')
            ->distinct()
            ->pluck('item_id')
            ->all();

        $lines = [];
        foreach ($requested as $index => $req) {
            $item = $items->get($req['item_id']);
            if ($item === null || $item->brand_id !== $outlet->brand_id) {
                throw new SalesException('ITEM_UNKNOWN', 'Menu tidak dikenal untuk outlet ini.', 422, field: "lines.{$index}.item_id");
            }

            $variant = null;
            if (! empty($req['variant_id'])) {
                /** @var ItemVariant|null $variant */
                $variant = $item->variants->firstWhere('id', $req['variant_id']);
                if ($variant === null) {
                    throw new SalesException('VARIANT_UNKNOWN', 'Varian tidak dikenal untuk menu ini.', 422, field: "lines.{$index}.variant_id");
                }
            }

            $catalog = BigDecimal::of($this->prices->resolve($item, $variant, $outlet->id, $channel->id));
            $mismatch = false;
            $unknownOption = false;
            $bundle = [];
            foreach ($req['bundle'] ?? [] as $choice) {
                $option = $item->bundleGroups->flatMap(fn ($g) => $g->options)->firstWhere('id', $choice['option_id']);
                if ($option === null) {
                    $mismatch = true;
                    $unknownOption = true;
                } else {
                    $catalog = $catalog->plus((string) $option->extra_price);
                }
                $bundle[] = [
                    'option_id' => $choice['option_id'],
                    'item_id' => $option?->item_id,
                    'variant_id' => $option?->item_variant_id,
                    'name' => $choice['name'] ?? null,
                    'extra_price' => (string) BigDecimal::of((string) $choice['extra_price'])->toScale(2),
                ];
            }

            $mods = [];
            $below = false;
            // Hanya perubahan harga yang tercatat (bukan sembarang suntingan menu) yang membenarkan harga lama.
            $changed = $knownAt !== null && in_array($item->id, $repriced, true);
            foreach ($req['modifiers'] ?? [] as $m) {
                $known = $modifiers->get($m['id']);
                $price = BigDecimal::of((string) $m['price'])->toScale(2);
                if ($known === null || ! $price->isEqualTo(BigDecimal::of((string) $known->price))) {
                    $mismatch = true;
                    $below = $below || $known === null || $price->isLessThan(BigDecimal::of((string) $known->price));
                    $changed = $changed || ($knownAt !== null && $known !== null && $known->updated_at !== null && $known->updated_at->greaterThan($knownAt));
                }
                $mods[] = [
                    'id' => $m['id'],
                    'group_id' => $known?->modifier_group_id,
                    'name' => $m['name'] ?? ($known !== null ? $known->name : ''),
                    'price' => (string) $price,
                    'qty' => (int) $m['qty'],
                ];
            }

            $unit = BigDecimal::of((string) $req['unit_price'])->toScale(2);
            $mismatch = $mismatch || ! $unit->isEqualTo($catalog);
            $below = $below || $unknownOption || $unit->isLessThan($catalog);

            $lines[] = [
                'id' => $req['id'],
                'index' => $index,
                'item' => $item,
                'variant' => $variant,
                'name' => $req['name'] ?? $item->name,
                'variant_name' => $req['variant_name'] ?? $variant?->name,
                'qty' => (string) BigDecimal::of((string) $req['qty']),
                'unit_price' => (string) $unit,
                'catalog_price' => (string) $catalog->toScale(2),
                'price_mismatch' => $mismatch,
                'below_catalog' => $below,
                'catalog_changed' => $changed,
                'price_override' => (bool) ($req['price_override'] ?? false),
                'modifiers' => $mods,
                'bundle' => $bundle,
                'discounts' => $req['discounts'] ?? [],
                'note' => $req['note'] ?? null,
                'sent_to_kitchen_at' => $req['sent_to_kitchen_at'] ?? null,
            ];
        }

        return $lines;
    }

    /** Perangkat yang belum pernah menarik data master tidak dapat membuktikan data yang dipakainya. */
    private function requirePulled(?CarbonImmutable $knownAt): void
    {
        if ($knownAt === null) {
            throw new SalesException('SYNC_REQUIRED', 'Perangkat belum menarik data master. Jalankan sinkronisasi lalu kirim ulang.', 409, true);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @return list<array{type: string, value: string, source: string, reason?: string|null}>
     */
    private function orderedDiscounts(array $discounts): array
    {
        $normalize = fn (array $d) => [
            'type' => (string) $d['type'],
            'value' => (string) BigDecimal::of((string) $d['value'])->toScale(2),
            'source' => (string) $d['source'],
            'reason' => $d['reason'] ?? null,
        ];
        $promo = array_values(array_filter($discounts, fn ($d) => $d['source'] !== 'manual'));
        $manual = array_values(array_filter($discounts, fn ($d) => $d['source'] === 'manual'));

        return array_map($normalize, [...$promo, ...$manual]);
    }

    /** @param  array<string, mixed>  $config */
    private function configDiffers(Outlet $outlet, SalesChannel $channel, array $config, string $taxName): bool
    {
        $current = [
            'tax_rate' => (string) BigDecimal::of((string) $outlet->tax_rate)->stripTrailingZeros(),
            'tax_inclusive' => (bool) $outlet->tax_inclusive,
            'tax_on_service_charge' => (bool) $outlet->tax_on_service_charge,
            'service_charge_rate' => (string) BigDecimal::of((string) $outlet->service_charge_rate)->stripTrailingZeros(),
            'service_charge_applies' => (bool) $channel->service_charge_applies,
            'rounding_unit' => (int) $outlet->rounding_unit,
            'rounding_mode' => (string) $outlet->rounding_mode,
        ];
        $sent = $config;
        $sent['tax_rate'] = (string) BigDecimal::of($config['tax_rate'])->stripTrailingZeros();
        $sent['service_charge_rate'] = (string) BigDecimal::of($config['service_charge_rate'])->stripTrailingZeros();

        return $current != $sent || $taxName !== $outlet->tax_name;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{type: string, value: string, source: string}>  $orderDiscounts
     * @param  list<string>  $codes
     * @return array{match: bool, untrusted: list<string>}
     */
    private function promotionsMatch(Outlet $outlet, SalesChannel $channel, CarbonImmutable $at, ?CarbonImmutable $knownAt, array $lines, array $orderDiscounts, array $codes, ?string $paymentMethod): array
    {
        $claimed = [];
        $claimedSources = [];
        foreach ($lines as $line) {
            foreach ($line['discounts'] as $d) {
                if ($d['source'] !== 'manual') {
                    $claimed[] = $line['id'].'|'.$d['source'].'|'.$d['type'].'|'.BigDecimal::of((string) $d['value'])->toScale(2);
                    $claimedSources[$d['source']] = true;
                }
            }
        }
        foreach ($orderDiscounts as $d) {
            if ($d['source'] !== 'manual') {
                $claimed[] = 'order|'.$d['source'].'|'.$d['type'].'|'.BigDecimal::of($d['value'])->toScale(2);
                $claimedSources[$d['source']] = true;
            }
        }

        $promos = Promotion::query()
            ->with(['targets', 'outlets'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $outlet->brand_id))
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->get()
            // Kuota dinilai terpisah (transaksi offline tetap sah walau kuota habis).
            ->map(fn (Promotion $p) => ['quota_remaining' => null] + $p->toEngineArray())
            ->all();

        $expected = [];
        if ($promos !== []) {
            $cart = array_map(function (array $line) {
                $unit = BigDecimal::of($line['unit_price']);
                foreach ($line['modifiers'] as $m) {
                    $unit = $unit->plus(BigDecimal::of($m['price'])->multipliedBy($m['qty']));
                }

                return [
                    'id' => $line['id'],
                    'item_id' => $line['item']->id,
                    'category_id' => $line['item']->category_id,
                    'brand_id' => $line['item']->brand_id,
                    'unit_price' => (string) $unit,
                    'qty' => $line['qty'],
                ];
            }, $lines);

            $result = $this->promotions->apply(['lines' => $cart], [
                'outlet_id' => $outlet->id,
                'channel_code' => $channel->code,
                'local_time' => $at->setTimezone($outlet->timezone)->format('Y-m-d H:i'),
                'timezone' => $outlet->timezone,
                'payment_method' => $paymentMethod,
                'codes' => $codes,
            ], array_values($promos));

            foreach ($result['line_discounts'] as $lineId => $discounts) {
                foreach ($discounts as $d) {
                    $expected[] = $lineId.'|'.$d['source'].'|'.$d['type'].'|'.BigDecimal::of($d['value'])->toScale(2);
                }
            }
            foreach ($result['order_discounts'] as $d) {
                $expected[] = 'order|'.$d['source'].'|'.$d['type'].'|'.BigDecimal::of($d['value'])->toScale(2);
            }
        }

        // Klaim yang tidak dihasilkan mesin promo hanya dipercaya bila promonya milik brand ini dan
        // berubah/dihapus setelah transaksi (perangkat memakai data promo lama).
        $untrusted = [];
        $unexplained = array_diff($claimed, $expected);
        $sources = [];
        foreach ($unexplained as $key) {
            $sources[explode('|', $key)[1]] = true;
        }
        if ($sources !== []) {
            $ids = array_map(fn (string $source) => substr($source, 6), array_keys($sources));
            $changed = $knownAt === null ? [] : Promotion::withTrashed()
                ->whereIn('id', $ids)
                ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $outlet->brand_id))
                ->where(fn ($q) => $q->where('updated_at', '>', $knownAt)->orWhere('deleted_at', '>', $knownAt))
                ->pluck('id')
                ->all();
            foreach (array_keys($sources) as $source) {
                if (! in_array(substr($source, 6), $changed, true)) {
                    $untrusted[] = $source;
                }
            }
        }

        sort($claimed);
        sort($expected);

        return ['match' => $claimed === $expected, 'untrusted' => $untrusted];
    }

    /**
     * Persentase diskon manual terhadap subtotal; null bila tidak ada diskon manual.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{type: string, value: string, source: string}>  $orderDiscounts
     * @param  list<string>  $manualSources
     */
    private function manualPercent(array $lines, array $orderDiscounts, BigDecimal $manualAmount, BigDecimal $subtotal, array $manualSources): ?BigDecimal
    {
        $percents = [];
        $any = false;
        foreach ($this->allDiscounts($lines, $orderDiscounts) as $d) {
            if (! in_array($d['source'], $manualSources, true)) {
                continue;
            }
            $any = true;
            if ($d['type'] === 'percent') {
                $percents[] = BigDecimal::of((string) $d['value']);
            }
        }
        if (! $any) {
            return null;
        }

        $effective = $subtotal->isPositive()
            ? $manualAmount->multipliedBy(100)->dividedBy($subtotal, 4, RoundingMode::UP)
            : BigDecimal::zero();

        return array_reduce($percents, fn (BigDecimal $max, BigDecimal $p) => $p->isGreaterThan($max) ? $p : $max, $effective);
    }

    /**
     * Pembatalan sebelum bayar (FR-POS-16) — tetap dikirim ke server untuk laporan anti-fraud.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $flags
     * @return array{reason: string, voided_by: string, authorized_by: string|null}
     */
    private function voidBeforePayment(Device $device, Outlet $outlet, array $data, CarbonImmutable $at, array &$flags): array
    {
        if (($data['payments'] ?? []) !== []) {
            throw new SalesException('VOID_WITH_PAYMENT', 'Transaksi yang dibatalkan sebelum bayar tidak boleh memiliki pembayaran. Gunakan void setelah bayar.', 422, field: 'payments');
        }

        $voider = $this->auth->staff($data['void']['voided_by'], $outlet, 'pos.transact', 'void.voided_by');
        $authorizedBy = null;
        if (! $this->auth->selfAuthorized($voider, 'pos.void', $outlet)) {
            [$supervisor, $offline] = $this->auth->verify($data['void']['authorization'] ?? null, 'void', $device, $at, 'void.authorization', $data['id'], ['reference_id' => $data['id']]);
            $authorizedBy = $supervisor->id;
            $flags['offline_authorization'] = ($flags['offline_authorization'] ?? false) || $offline;
        }

        return ['reason' => trim((string) $data['void']['reason']), 'voided_by' => $voider->id, 'authorized_by' => $authorizedBy];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $flags
     * @return list<array<string, mixed>>
     */
    private function payments(Device $device, Outlet $outlet, array $data, BigDecimal $total, array &$flags): array
    {
        $configs = $this->methods->forOutlet($outlet)->keyBy('method');
        $sum = BigDecimal::zero();
        $rows = [];

        foreach ($data['payments'] as $i => $p) {
            $method = (string) $p['method'];
            if (! in_array($method, PaymentMethods::codes(), true)) {
                throw new SalesException('PAYMENT_METHOD_UNKNOWN', 'Metode pembayaran tidak dikenal.', 422, field: "payments.{$i}.method");
            }
            $config = $configs->get($method);
            if ($config === null || ! $config->is_active) {
                $flags['payment_method_inactive'] = true;
            }

            $amount = BigDecimal::of((string) $p['amount'])->toScale(2);
            $tendered = isset($p['tendered']) ? BigDecimal::of((string) $p['tendered'])->toScale(2) : null;
            $change = BigDecimal::zero();
            if ($method === 'cash') {
                if ($tendered !== null) {
                    if ($tendered->isLessThan($amount)) {
                        throw new SalesException('TENDERED_TOO_LOW', 'Uang diterima kurang dari nominal pembayaran.', 422, field: "payments.{$i}.tendered");
                    }
                    $change = $tendered->minus($amount);
                }
            } elseif ($tendered !== null && ! $tendered->isEqualTo($amount)) {
                // Kembalian hanya dari tunai (FR-PAY-03).
                throw new SalesException('CHANGE_NOT_ALLOWED', 'Kembalian hanya dapat diberikan dari pembayaran tunai.', 422, field: "payments.{$i}.tendered");
            }

            $intentId = $p['payment_intent_id'] ?? null;
            if (in_array($method, PaymentMethods::GATEWAY_METHODS, true)) {
                $intentId = $this->checkIntent($device, $data['id'], $method, $amount, $intentId, $i);
            } elseif ($intentId !== null) {
                throw new SalesException('PAYMENT_INTENT_INVALID', 'Tagihan gateway hanya untuk QRIS/e-wallet.', 422, field: "payments.{$i}.payment_intent_id");
            }

            $sum = $sum->plus($amount);
            $rows[] = [
                'id' => $p['id'],
                'method' => $method,
                'amount' => (string) $amount,
                'tendered' => $tendered === null ? null : (string) $tendered,
                'change_amount' => (string) $change->toScale(2),
                'reference' => $p['reference'] ?? null,
                'payment_intent_id' => $intentId,
                'mdr_amount' => PaymentMethods::mdr($config, (string) $amount),
                'device_created_at' => $this->shifts->time($p['created_at'], "payments.{$i}.created_at"),
            ];
        }

        if (! $sum->isEqualTo($total)) {
            throw new SalesException('PAYMENT_TOTAL_MISMATCH', 'Jumlah pembayaran harus sama dengan total tagihan.', 422, field: 'payments', details: ['total' => (string) $total, 'paid' => (string) $sum->toScale(2)]);
        }

        return $rows;
    }

    private function checkIntent(Device $device, string $orderId, string $method, BigDecimal $amount, ?string $intentId, int $i): string
    {
        $field = "payments.{$i}.payment_intent_id";
        /** @var PaymentIntent|null $intent */
        $intent = $intentId === null ? null : PaymentIntent::query()->find($intentId);
        if ($intent === null || $intent->device_id !== $device->id || $intent->method !== $method || $intent->order_ref !== $orderId
            || ! BigDecimal::of((string) $intent->amount)->isEqualTo($amount)) {
            throw new SalesException('PAYMENT_INTENT_INVALID', 'Pembayaran QRIS/e-wallet harus merujuk tagihan gateway yang sesuai.', 422, field: $field);
        }

        if ($intent->status === PaymentIntent::PENDING) {
            $intent = $this->intents->refresh($intent);
        }
        if ($intent->status === PaymentIntent::PENDING) {
            throw new SalesException('PAYMENT_PENDING', 'Pembayaran belum terkonfirmasi gateway.', 409, true, $field);
        }
        if ($intent->status !== PaymentIntent::PAID) {
            throw new SalesException('PAYMENT_NOT_PAID', 'Tagihan gateway tidak berstatus lunas.', 422, field: $field, details: ['status' => $intent->status]);
        }
        if (! $this->intents->consume($intent->id, $orderId)) {
            throw new SalesException('PAYMENT_INTENT_USED', 'Tagihan gateway sudah dipakai transaksi lain.', 409, field: $field);
        }

        return $intent->id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, string>>  $calculated
     * @param  array{reason: string, voided_by: string, authorized_by: string|null}|null  $void
     */
    private function storeItems(Order $order, array $lines, array $calculated, ?array $void): void
    {
        $byId = collect($calculated)->keyBy('id');
        $now = now();
        $rows = [];
        foreach ($lines as $n => $line) {
            /** @var Item $item */
            $item = $line['item'];
            $calc = $byId->get($line['id']);
            $rows[] = [
                'id' => $line['id'],
                'business_date' => $order->business_date->format('Y-m-d'),
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'line_no' => $n + 1,
                'item_id' => $item->id,
                'item_variant_id' => $line['variant']?->id,
                'item_type' => $item->type,
                'sku' => $item->sku,
                'name' => mb_substr((string) $line['name'], 0, 100),
                'variant_name' => $line['variant_name'] === null ? null : mb_substr((string) $line['variant_name'], 0, 40),
                'category_id' => $item->category_id,
                'kitchen_station_id' => $item->kitchen_station_id,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'catalog_price' => $line['catalog_price'],
                'modifiers' => json_encode($line['modifiers']),
                'bundle' => json_encode($line['bundle']),
                'gross' => $calc['gross'],
                'item_discount' => $calc['item_discount'],
                'order_discount' => $calc['order_discount'],
                'net' => $calc['net'],
                'status' => $void === null ? 'sold' : 'voided',
                'void_reason' => $void['reason'] ?? null,
                'voided_by' => $void['voided_by'] ?? null,
                'void_authorized_by' => $void['authorized_by'] ?? null,
                'sent_to_kitchen_at' => $line['sent_to_kitchen_at'] === null ? null : CarbonImmutable::parse($line['sent_to_kitchen_at'])->utc(),
                'note' => $line['note'],
                'created_at' => $now,
            ];
        }
        OrderItem::query()->insert($rows);
    }

    /**
     * Rincian diskon per sumber untuk laporan diskon & promo (FR-RPT, BR-14).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{type: string, value: string, source: string, reason?: string|null}>  $orderDiscounts
     */
    private function storeDiscounts(Order $order, array $lines, array $orderDiscounts, BigDecimal $afterItem, User $cashier, ?string $authorizedBy): void
    {
        $rows = [];
        $claimedIds = [];
        foreach ($this->allDiscounts($lines, $orderDiscounts) as $d) {
            if (str_starts_with((string) $d['source'], 'promo:')) {
                $claimedIds[] = substr((string) $d['source'], 6);
            }
        }
        $knownPromos = $claimedIds === [] ? [] : Promotion::withTrashed()->whereIn('id', array_unique($claimedIds))->pluck('id')->all();
        $push = function (?string $itemId, array $d, BigDecimal $amount) use (&$rows, $order, $cashier, $authorizedBy, $knownPromos): void {
            $isPromo = str_starts_with($d['source'], 'promo:');
            $promotionId = $isPromo ? substr($d['source'], 6) : null;
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'company_id' => $order->company_id,
                'order_id' => $order->id,
                'business_date' => $order->business_date->format('Y-m-d'),
                'order_item_id' => $itemId,
                'source' => $isPromo ? 'promo' : 'manual',
                // ID promo yang tidak dikenal company ini tidak disimpan sebagai rujukan.
                'promotion_id' => $promotionId !== null && in_array($promotionId, $knownPromos, true) ? $promotionId : null,
                'type' => $d['type'],
                'value' => $d['value'],
                'amount' => (string) $amount->toScale(2, RoundingMode::HALF_UP),
                'cashier_id' => $cashier->id,
                'authorized_by' => $isPromo ? null : $authorizedBy,
                'reason' => isset($d['reason']) ? mb_substr((string) $d['reason'], 0, 200) : null,
                'created_at' => now(),
            ];
        };

        foreach ($lines as $line) {
            $remaining = BigDecimal::of($line['unit_price']);
            foreach ($line['modifiers'] as $m) {
                $remaining = $remaining->plus(BigDecimal::of($m['price'])->multipliedBy($m['qty']));
            }
            $remaining = $remaining->multipliedBy($line['qty']);
            foreach ($this->orderedDiscounts($line['discounts']) as $d) {
                $amount = $this->discountAmount($d, $remaining);
                $remaining = $remaining->minus($amount);
                $push($line['id'], $d, $amount);
            }
        }

        $remaining = $afterItem;
        foreach ($orderDiscounts as $d) {
            $amount = $this->discountAmount($d, $remaining)->toScale(2, RoundingMode::HALF_UP);
            $remaining = $remaining->minus($amount);
            $push(null, $d, $amount);
        }

        if ($rows !== []) {
            OrderDiscount::query()->insert($rows);
        }
    }

    /** @param  array{type: string, value: string}  $d */
    private function discountAmount(array $d, BigDecimal $remaining): BigDecimal
    {
        $value = BigDecimal::of($d['value']);
        $amount = $d['type'] === 'percent'
            ? $remaining->multipliedBy($value)->dividedBy(100, 20, RoundingMode::HALF_UP)
            : $value;

        return $amount->isGreaterThan($remaining) ? $remaining : $amount;
    }

    /** @param  list<array<string, mixed>>  $payments */
    private function storePayments(Order $order, array $payments): void
    {
        foreach ($payments as $p) {
            $row = new OrderPayment;
            $row->forceFill($p + [
                'business_date' => $order->business_date->format('Y-m-d'),
                'order_id' => $order->id,
                'company_id' => $order->company_id,
                'outlet_id' => $order->outlet_id,
                'shift_id' => $order->shift_id,
            ])->save();
        }
    }

    /**
     * Kurangi kuota promo; kuota yang terlampaui karena transaksi offline ditandai (ADR 0004 butir 5).
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array{type: string, value: string, source: string}>  $orderDiscounts
     */
    private function countPromotionUse(Order $order, array $lines, array $orderDiscounts): void
    {
        $ids = [];
        foreach ($this->allDiscounts($lines, $orderDiscounts) as $d) {
            if (str_starts_with((string) $d['source'], 'promo:')) {
                $ids[substr((string) $d['source'], 6)] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        $exceeded = false;
        foreach (array_keys($ids) as $id) {
            $row = DB::selectOne(
                'UPDATE promotions SET used_count = used_count + 1 WHERE id = ? AND company_id = ? RETURNING used_count, quota',
                [$id, $order->company_id],
            );
            if ($row !== null && $row->quota !== null && (int) $row->used_count > (int) $row->quota) {
                $exceeded = true;
            }
        }

        if ($exceeded) {
            $order->forceFill(['flags' => array_values(array_unique([...$order->flags, 'promo_quota_exceeded']))])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $orderDiscounts
     * @return list<array<string, mixed>>
     */
    private function allDiscounts(array $lines, array $orderDiscounts): array
    {
        $all = [];
        foreach ($lines as $line) {
            foreach ($line['discounts'] as $d) {
                $all[] = $d;
            }
        }

        return [...$all, ...$orderDiscounts];
    }
}
