<?php

namespace App\Prototype;

use App\Modules\Catalog\Application\PriceResolver;
use App\Modules\Catalog\Domain\Pricing\PricingCalculator;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\OrderRecorder;
use App\Modules\Sales\Application\ReceiptNumber;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Application\ShiftService;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Jembatan PROTOTIPE POS ke mesin penjualan yang sebenarnya.
 *
 * Dipakai hanya untuk demo: layar POS memakai katalog nyata milik company demo,
 * dan tombol Bayar menyimpan transaksi lewat OrderRecorder — sehingga hasilnya
 * langsung terlihat di back-office (Penjualan, Laporan, Tutup Hari).
 *
 * Seluruh pekerjaan berjalan di dalam konteks tenant company demo, jadi RLS dan
 * global scope tetap berlaku seperti permintaan biasa.
 */
class PosBridge
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PriceResolver $prices,
        private readonly PricingCalculator $calculator,
        private readonly OrderRecorder $recorder,
        private readonly ShiftService $shifts,
        private readonly ShiftReport $report,
        private readonly BusinessCalendar $calendar,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('fnb.prototype_pos_live', true);
    }

    /**
     * Katalog + identitas outlet untuk layar POS. Null bila data demo belum ada.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $company = Company::query()->orderBy('created_at')->first();
            if ($company === null) {
                return null;
            }

            return $this->tenant->runAsTenant($company->id, function () use ($company): ?array {
                $ctx = $this->context();
                if ($ctx === null) {
                    return null;
                }
                [$outlet, $device, $cashier, $channel] = $ctx;

                return [
                    'company' => $company->name,
                    'outlet' => $outlet->name,
                    'outlet_code' => $outlet->code,
                    'address' => trim((string) ($outlet->address ?? '')) ?: $outlet->name,
                    'device' => $device->code,
                    'cashier' => $cashier->name,
                    'menu' => $this->menu($outlet, $channel),
                    'pricing' => [
                        'tax_name' => $outlet->tax_name,
                        'tax_rate' => (float) $outlet->tax_rate,
                        'tax_inclusive' => (bool) $outlet->tax_inclusive,
                        'service_charge_rate' => (float) $outlet->service_charge_rate,
                        'service_charge_applies' => (bool) $channel->service_charge_applies,
                        'rounding_unit' => (int) $outlet->rounding_unit,
                    ],
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('PosBridge snapshot gagal: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Simpan transaksi sungguhan dari layar POS.
     *
     * @param  list<array{item_id: string, qty: int|float, note?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function checkout(array $lines, string $channelCode, string $method, ?string $tendered, ?string $table): array
    {
        $company = Company::query()->orderBy('created_at')->firstOrFail();

        return $this->tenant->runAsTenant($company->id, function () use ($lines, $channelCode, $method, $tendered, $table): array {
            $ctx = $this->context($channelCode);
            if ($ctx === null) {
                throw new \RuntimeException('Data demo (outlet/perangkat/kasir) belum lengkap.');
            }
            [$outlet, $device, $cashier, $channel] = $ctx;

            $shift = $this->openShift($device, $outlet, $cashier->id);
            $businessDate = CarbonImmutable::parse($shift->business_date);
            $now = CarbonImmutable::now();

            // Baris transaksi memakai harga katalog yang sama dengan yang tampil di layar,
            // sehingga tidak dianggap ubah harga manual.
            $items = Item::query()->with(['variants', 'prices', 'bundleGroups.options'])
                ->whereIn('id', array_column($lines, 'item_id'))->get()->keyBy('id');
            $payloadLines = [];
            $calcLines = [];
            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                if ($item === null) {
                    throw new \RuntimeException('Menu tidak dikenal.');
                }
                $price = (string) BigDecimal::of($this->prices->resolve($item, null, $outlet->id, $channel->id))->toScale(2);
                $qty = (string) BigDecimal::of((string) $line['qty'])->toScale(0);
                $id = (string) Str::uuid();
                $payloadLines[] = [
                    'id' => $id,
                    'item_id' => $item->id,
                    'name' => $item->name,
                    'qty' => $qty,
                    'unit_price' => $price,
                    'note' => $line['note'] ?? null,
                    'modifiers' => [],
                    'discounts' => [],
                ];
                $calcLines[] = ['id' => $id, 'unit_price' => $price, 'qty' => $qty, 'modifiers' => [], 'discounts' => []];
            }

            $config = [
                'tax_rate' => (string) $outlet->tax_rate,
                'tax_inclusive' => (bool) $outlet->tax_inclusive,
                'tax_on_service_charge' => (bool) $outlet->tax_on_service_charge,
                'service_charge_rate' => (string) $outlet->service_charge_rate,
                'service_charge_applies' => (bool) $channel->service_charge_applies,
                'rounding_unit' => (int) $outlet->rounding_unit,
                'rounding_mode' => (string) $outlet->rounding_mode,
            ];
            $totals = $this->calculator->calculate([
                'config' => $config,
                'lines' => $calcLines,
                'order_discounts' => [],
            ])['totals'];

            $pricing = $config + ['tax_name' => $outlet->tax_name];
            $total = (string) BigDecimal::of($totals['total'])->toScale(2);

            $sequence = (int) Order::query()
                ->where('device_id', $device->id)
                ->where('business_date', $businessDate->format('Y-m-d'))
                ->count() + 1;

            $order = null;
            $lastError = null;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $payload = [
                    'id' => (string) Str::uuid(),
                    'shift_id' => $shift->id,
                    'cashier_id' => $cashier->id,
                    'receipt_no' => ReceiptNumber::make($outlet, $device, $businessDate, $sequence + $attempt),
                    'channel_code' => $channel->code,
                    'table_label' => $table !== null && $table !== '' ? mb_substr($table, 0, 30) : null,
                    'status' => Order::PAID,
                    'created_at' => $now->toIso8601String(),
                    'completed_at' => $now->toIso8601String(),
                    'pricing' => $pricing,
                    'lines' => $payloadLines,
                    'order_discounts' => [],
                    'totals' => array_intersect_key($totals, array_flip(OrderRecorder::TOTAL_KEYS)),
                    'payments' => [[
                        'id' => (string) Str::uuid(),
                        'method' => $method,
                        'amount' => $total,
                        'tendered' => $method === 'cash' && $tendered !== null
                            ? (string) BigDecimal::of($tendered)->toScale(2)
                            : null,
                        'created_at' => $now->toIso8601String(),
                    ]],
                ];

                try {
                    $order = $this->recorder->record($device, $payload);
                    break;
                } catch (SalesException $e) {
                    $lastError = $e;
                    if ($e->errorCode !== 'DUPLICATE_RECEIPT_NO') {
                        throw $e;
                    }
                }
            }

            if ($order === null) {
                throw $lastError ?? new \RuntimeException('Transaksi gagal disimpan.');
            }

            return [
                'order_id' => $order->id,
                'receipt_no' => $order->receipt_no,
                'business_date' => $businessDate->format('d M Y'),
                'totals' => array_map(
                    fn ($v) => (string) BigDecimal::of((string) $v)->toScale(2),
                    array_intersect_key($totals, array_flip(OrderRecorder::TOTAL_KEYS)),
                ),
                'total' => $total,
                'outlet' => $outlet->name,
                'address' => trim((string) ($outlet->address ?? '')),
                'cashier' => $cashier->name,
                'channel' => $channel->name,
                'method' => $method,
                'tendered' => $method === 'cash' ? $tendered : null,
                'backoffice_url' => url('/admin'),
            ];
        });
    }

    /**
     * Outlet, perangkat, kasir, dan channel untuk demo.
     *
     * @return array{0: Outlet, 1: Device, 2: User, 3: SalesChannel}|null
     */
    private function context(string $channelCode = 'dine_in'): ?array
    {
        $device = Device::query()->with('outlet')->orderBy('created_at')->first();
        if ($device === null || $device->outlet === null) {
            return null;
        }
        $outlet = $device->outlet;

        $channel = SalesChannel::query()->where('code', $channelCode)->first()
            ?? SalesChannel::query()->where('code', 'dine_in')->first()
            ?? SalesChannel::query()->orderBy('code')->first();
        if ($channel === null) {
            return null;
        }

        $cashier = $this->cashier($outlet);
        if ($cashier === null) {
            return null;
        }

        return [$outlet, $device, $cashier, $channel];
    }

    private function cashier(Outlet $outlet): ?User
    {
        $members = CompanyUser::query()->with('user')->where('is_active', true)->get();
        foreach ($members as $member) {
            $user = $member->user;
            if (! $user instanceof User) {
                continue;
            }
            try {
                // Memakai penjaga yang sama dengan endpoint POS: izin + cakupan outlet.
                $this->recorderStaffCheck($user->id, $outlet);

                return $user;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function recorderStaffCheck(string $userId, Outlet $outlet): void
    {
        $auth = app(\App\Modules\Sales\Application\Authorizations::class);
        $auth->staff($userId, $outlet, 'pos.transact');
        $auth->staff($userId, $outlet, 'pos.shift');
    }

    private function openShift(Device $device, Outlet $outlet, string $cashierId): Shift
    {
        $today = $this->calendar->businessDate($outlet, CarbonImmutable::now())->format('Y-m-d');
        $open = Shift::query()->where('device_id', $device->id)->where('status', Shift::OPEN)->first();
        if ($open !== null) {
            if (CarbonImmutable::parse($open->business_date)->format('Y-m-d') === $today) {
                return $open;
            }
            // Shift lama dari hari bisnis sebelumnya ditutup dulu agar transaksi demo
            // masuk ke hari ini (kas dihitung sama dengan yang seharusnya, tanpa selisih).
            $expected = (string) $this->report->build($open)['cash']['expected'];
            $closedAt = CarbonImmutable::now();
            if ($closedAt->lessThan(CarbonImmutable::parse($open->opened_at))) {
                $closedAt = CarbonImmutable::parse($open->opened_at)->addMinute();
            }
            $this->shifts->close($device, $open->id, [
                'closed_at' => $closedAt->toIso8601String(),
                'closed_by' => $cashierId,
                'counted_cash' => $expected,
            ]);
        }

        return $this->shifts->open($device, [
            'id' => (string) Str::uuid(),
            'cashier_id' => $cashierId,
            'opening_cash' => '500000.00',
            'opened_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Menu nyata outlet, dikelompokkan per kategori.
     *
     * @return list<array<string, mixed>>
     */
    private function menu(Outlet $outlet, SalesChannel $channel): array
    {
        $items = Item::query()
            ->with(['variants', 'prices'])
            ->where('brand_id', $outlet->brand_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $categories = MenuCategory::query()->whereIn('id', $items->pluck('category_id')->filter()->unique())->get()->keyBy('id');
        $groups = [];
        foreach ($items as $item) {
            $catName = $categories->get($item->category_id)?->name ?? 'Lainnya';
            $slug = DemoPos::slug($item->name);
            $groups[$catName] ??= ['name' => $catName, 'items' => []];
            $groups[$catName]['items'][] = [
                'id' => $item->id,
                'name' => $item->name,
                'price' => (float) $this->prices->resolve($item, null, $outlet->id, $channel->id),
                'slug' => $slug,
                'image' => $this->imageUrl($slug),
                'initials' => $this->initials($item->name),
                'note' => null,
                'mod' => null,
                'out' => false,
            ];
        }

        return array_values($groups);
    }

    private function imageUrl(string $slug): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $rel = DemoPos::IMAGE_DIR.'/'.$slug.'.'.$ext;
            if (is_file(public_path($rel))) {
                return asset($rel).'?v='.@filemtime(public_path($rel));
            }
        }

        return null;
    }

    private function initials(string $name): string
    {
        preg_match_all('/[A-Za-z]/', mb_substr($name, 0, 40), $m);
        $words = preg_split('/\s+/', $name) ?: [];
        $out = '';
        foreach ($words as $w) {
            $c = mb_substr($w, 0, 1);
            if (preg_match('/[A-Za-z]/', $c)) {
                $out .= mb_strtoupper($c);
            }
            if (mb_strlen($out) >= 2) {
                break;
            }
        }

        return $out !== '' ? $out : '#';
    }
}
