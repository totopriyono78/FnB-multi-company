<?php

namespace Tests\Support;

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Skenario POS standar untuk uji Tahap 3.
 *
 * Outlet KMG: PB1 10% (di luar harga), service charge 5% (kena pajak), pembulatan Rp100 terdekat.
 * Menu: Kopi Susu 18.000 (Large 22.000), Croissant 25.000.
 */
final class Pos
{
    public Company $company;

    public Outlet $outlet;

    public Outlet $otherOutlet;

    public Device $device;

    public string $deviceToken;

    public Item $coffee;

    public Item $croissant;

    /** @var array<string, array{user: User, member: CompanyUser, pin: string}> */
    public array $staff = [];

    public static function setup(string $name = 'Kopi Tepi Jalan', string $outletCode = 'KMG'): self
    {
        $pos = new self;
        [$pos->company] = Factory::company($name);
        $brand = Factory::brand($pos->company, ['code' => 'B'.strtoupper(Str::random(3))]);
        $pos->outlet = Factory::outlet($pos->company, $brand, ['code' => $outletCode]);
        Factory::tenant($pos->company, fn () => Outlet::query()->whereKey($pos->outlet->id)->update([
            'tax_name' => 'PB1', 'tax_rate' => '10', 'tax_inclusive' => false, 'tax_on_service_charge' => true,
            'service_charge_rate' => '5', 'rounding_unit' => 100, 'rounding_mode' => 'nearest',
            'timezone' => 'Asia/Jakarta', 'business_day_cutoff' => '00:00',
        ]));
        $pos->outlet = Factory::tenant($pos->company, fn () => Outlet::query()->findOrFail($pos->outlet->id));
        $pos->otherOutlet = Factory::outlet($pos->company, $brand, ['code' => 'DGO']);
        [$pos->device, $pos->deviceToken] = Factory::pairedDevice($pos->company, $pos->outlet);

        $pos->coffee = Menu::item($pos->company, $brand, [
            'name' => 'Kopi Susu', 'sku' => 'KS', 'base_price' => '18000',
            'variants' => [['name' => 'Regular', 'price' => '18000', 'is_default' => true], ['name' => 'Large', 'price' => '22000']],
        ]);
        $pos->croissant = Menu::item($pos->company, $brand, ['name' => 'Croissant', 'sku' => 'CRS', 'base_price' => '25000']);

        foreach ([
            'cashier' => [['cashier'], '7351'],
            'manager' => [['outlet_manager'], '482915'],
            'admin' => [['company_admin'], '615283'],
            'kitchen' => [['kitchen'], '5566'],
        ] as $key => [$roles, $pin]) {
            [$user, $member] = Factory::staff($pos->company, $roles, $key === 'admin' ? [] : [$pos->outlet->id], $pin);
            $pos->staff[$key] = ['user' => $user, 'member' => $member, 'pin' => $pin];
        }
        [$user, $member] = Factory::staff($pos->company, ['cashier'], [$pos->otherOutlet->id], '3867');
        $pos->staff['other_cashier'] = ['user' => $user, 'member' => $member, 'pin' => '3867'];
        $pos->backdate();
        // Perangkat menarik data master sebelum berjualan (acuan validasi harga, ADR 0004).
        test()->getJson('/api/v1/sync/pull', bearer($pos->deviceToken))->assertOk();

        return $pos;
    }

    /**
     * Data master dibuat "kemarin" agar transaksi uji (10 menit lalu) tidak dianggap memakai data yang
     * berubah setelah transaksi — kondisi normal di produksi.
     */
    public function backdate(): void
    {
        $past = now()->subDay();
        Factory::tenant($this->company, function () use ($past): void {
            foreach (['items', 'item_variants', 'modifiers', 'modifier_groups', 'promotions', 'outlets', 'sales_channels'] as $table) {
                DB::table($table)->update(['updated_at' => $past]);
            }
        });
        $this->outlet = Factory::tenant($this->company, fn () => Outlet::query()->findOrFail($this->outlet->id));
    }

    /** Tambah staf dengan PIN ke skenario. */
    public function addStaff(string $key, User $user, CompanyUser $member, string $pin): void
    {
        $this->staff[$key] = ['user' => $user, 'member' => $member, 'pin' => $pin];
    }

    /** @var array<string, string> */
    private array $tokens = [];

    /**
     * Kirim entitas memakai token PIN staf (pelaku terverifikasi), bukan token perangkat.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pushAs(string $who, string $type, array $payload, ?string $id = null): array
    {
        return $this->push($type, $payload, $id, $this->token($who));
    }

    /** Token PIN yang dipakai ulang (login baru mencabut sesi lama di perangkat yang sama). */
    public function token(string $who): string
    {
        return $this->tokens[$who] ??= $this->login($who);
    }

    public function userId(string $who): string
    {
        return $this->staff[$who]['user']->id;
    }

    /** Token kasir (login PIN) di perangkat. */
    public function login(string $who): string
    {
        $response = test()->postJson('/api/v1/pos/auth/pin', [
            'staff_id' => $this->staff[$who]['member']->id,
            'pin' => $this->staff[$who]['pin'],
        ], bearer($this->deviceToken));
        $response->assertOk();

        return (string) $response->json('data.token');
    }

    /** Buka shift lewat sinkronisasi; mengembalikan [shift_id, business_date]. */
    public function openShift(string $who = 'cashier', string $openingCash = '500000'): array
    {
        $id = (string) Str::uuid7();
        $response = test()->postJson('/api/v1/sync/push', [
            'batch_id' => (string) Str::uuid7(),
            'entities' => [[
                'type' => 'shift.open',
                'id' => $id,
                'payload' => ['cashier_id' => $this->userId($who), 'opening_cash' => $openingCash, 'opened_at' => now()->subHour()->toIso8601String()],
            ]],
        ], bearer($this->deviceToken));
        $response->assertOk()->assertJsonPath('data.results.0.status', 'accepted');

        $date = Factory::tenant($this->company, fn () => Shift::query()->findOrFail($id)->business_date->format('ymd'));

        return [$id, $date];
    }

    public function receipt(string $ymd, int $seq): string
    {
        return sprintf('%s-%s-%s-%04d', $this->outlet->code, $this->device->code, $ymd, $seq);
    }

    /**
     * Pesanan dine-in standar: 2 Croissant + 1 Kopi Regular.
     * Subtotal 68.000; SC 5% = 3.400; PB1 10% × 71.400 = 7.140; sebelum pembulatan 78.540 → 78.500 (−40).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function order(string $shiftId, string $receipt, array $overrides = []): array
    {
        $regular = $this->coffee->variants->firstWhere('name', 'Regular');

        return array_replace([
            'shift_id' => $shiftId,
            'cashier_id' => $this->userId('cashier'),
            'receipt_no' => $receipt,
            'channel_code' => 'dine_in',
            'status' => 'paid',
            'created_at' => now()->subMinutes(5)->toIso8601String(),
            'completed_at' => now()->subMinutes(4)->toIso8601String(),
            'pricing' => $this->pricing(),
            'lines' => [
                ['id' => (string) Str::uuid7(), 'item_id' => $this->croissant->id, 'name' => 'Croissant', 'qty' => 2, 'unit_price' => '25000'],
                ['id' => (string) Str::uuid7(), 'item_id' => $this->coffee->id, 'variant_id' => $regular->id, 'name' => 'Kopi Susu', 'variant_name' => 'Regular', 'qty' => 1, 'unit_price' => '18000'],
            ],
            'order_discounts' => [],
            'totals' => ['subtotal' => '68000', 'item_discount' => '0', 'order_discount' => '0', 'service_charge' => '3400', 'tax' => '7140', 'rounding' => '-40', 'total' => '78500'],
            'payments' => [
                ['id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '78500', 'tendered' => '100000', 'created_at' => now()->subMinutes(4)->toIso8601String()],
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    public function pricing(bool $serviceCharge = true): array
    {
        return [
            'tax_name' => 'PB1', 'tax_rate' => '10', 'tax_inclusive' => false, 'tax_on_service_charge' => true,
            'service_charge_rate' => '5', 'service_charge_applies' => $serviceCharge, 'rounding_unit' => 100, 'rounding_mode' => 'nearest',
        ];
    }

    /**
     * Kirim satu entitas lewat /sync/push dan kembalikan hasilnya.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function push(string $type, array $payload, ?string $id = null, ?string $token = null): array
    {
        $response = test()->postJson('/api/v1/sync/push', [
            'batch_id' => (string) Str::uuid7(),
            'entities' => [['type' => $type, 'id' => $id ?? (string) Str::uuid7(), 'payload' => $payload]],
        ], bearer($token ?? $this->deviceToken));
        $response->assertOk();

        return (array) $response->json('data.results.0');
    }

    /**
     * Otorisasi supervisor online (POST /pos/authorize). `reference_id` = ID transaksi yang disetujui.
     *
     * @param  array<string, mixed>  $extra
     */
    public function authorize(string $action, string $who = 'manager', array $extra = []): string
    {
        $response = test()->postJson('/api/v1/pos/authorize', [
            'action' => $action,
            'supervisor_id' => $this->staff[$who]['member']->id,
            'pin' => $this->staff[$who]['pin'],
            'reason' => 'Disetujui untuk uji',
        ] + $extra, bearer($this->deviceToken));
        $response->assertOk();

        return (string) $response->json('data.authorization_id');
    }

    /** @param  \Closure(): mixed  $fn */
    public function tenant(\Closure $fn): mixed
    {
        return Factory::tenant($this->company, $fn);
    }
}
