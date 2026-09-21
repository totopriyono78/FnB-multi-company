<?php

namespace App\Modules\Sync\Application;

use App\Modules\Catalog\Application\PosCatalogBuilder;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;

/**
 * Data master untuk POS (FR-DEV-03, ADR 0004). Snapshot penuh bila versi perangkat tertinggal (server wins).
 */
class SyncPullService
{
    public function __construct(
        private readonly SyncVersions $versions,
        private readonly PosCatalogBuilder $catalog,
        private readonly PaymentMethods $methods,
        private readonly AccessScope $scope,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** @return array<string, mixed> */
    public function pull(Device $device, ?int $since): array
    {
        $device->loadMissing('outlet');
        $version = $this->versions->current($device->company_id);
        $state = $this->state($device);

        if ($since !== null && $since === $version) {
            return ['mode' => 'none', 'version' => $version] + $state;
        }

        $outlet = $device->outlet;
        // Dicatat sebelum snapshot disusun: perubahan yang terjadi bersamaan dianggap belum diketahui perangkat.
        Device::query()->whereKey($device->id)->update(['master_pulled_at' => now(), 'master_version' => $version]);

        return [
            'mode' => 'full',
            'version' => $version,
            ...$state,
            'snapshot' => [
                'outlet' => [
                    'id' => $outlet->id,
                    'code' => $outlet->code,
                    'name' => $outlet->name,
                    'address' => $outlet->address,
                    'phone' => $outlet->phone,
                    'npwpd' => $outlet->npwpd,
                    'timezone' => $outlet->timezone,
                    'business_day_cutoff' => substr((string) $outlet->business_day_cutoff, 0, 5),
                    'receipt_settings' => (object) ($outlet->receipt_settings ?? []),
                ],
                'device' => ['id' => $device->id, 'code' => $device->code, 'name' => $device->name],
                'receipt_format' => '{KODE_OUTLET}-{KODE_PERANGKAT}-{YYMMDD}-{URUT4}',
                'catalog' => $this->catalog->build($outlet),
                'payment_methods' => $this->methods->forOutlet($outlet)
                    ->map(fn (OutletPaymentMethod $m) => [
                        'method' => $m->method,
                        'label' => $m->label,
                        'is_active' => $m->is_active,
                        'sort_order' => $m->sort_order,
                        'via_gateway' => in_array($m->method, PaymentMethods::GATEWAY_METHODS, true),
                    ])->values()->all(),
                'staff' => $this->staff($device),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function state(Device $device): array
    {
        $open = Shift::query()->where('device_id', $device->id)->where('status', Shift::OPEN)->first();
        $today = $this->calendar->today($device->outlet);

        return [
            'server_time' => now()->toIso8601String(),
            'business_date' => $today->format('Y-m-d'),
            'business_day_closed' => $this->calendar->isClosed($device->outlet, $today),
            'open_shift' => $open === null ? null : [
                'id' => $open->id,
                'cashier_id' => $open->cashier_id,
                'business_date' => $open->business_date->format('Y-m-d'),
                'opened_at' => $open->opened_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Staf yang dapat login di outlet perangkat beserta izin POS. Hash PIN tidak dikirim;
     * verifikasi PIN offline dirancang di Tahap 6.
     *
     * @return list<array<string, mixed>>
     */
    private function staff(Device $device): array
    {
        $members = CompanyUser::query()
            ->with(['user.roles.permissions', 'user.permissions'])
            ->where('is_active', true)
            ->whereNotNull('pin_hash')
            ->orderBy('employee_code')
            ->get();
        $this->scope->prime($members);

        return $members
            ->filter(fn (CompanyUser $m) => $this->scope->allowsOutlet($m->user, $device->outlet))
            ->map(function (CompanyUser $m): array {
                $permissions = $m->user->getAllPermissions()->pluck('name')
                    ->filter(fn (string $p) => str_starts_with($p, 'pos.') || $p === 'menu.sold_out')
                    ->values()->all();
                $max = $m->user->roles->max(fn ($r) => (float) ($r instanceof Role ? $r->max_discount_percent : 0));

                return [
                    'id' => $m->id,
                    'user_id' => $m->user_id,
                    'name' => $m->user->name,
                    'employee_code' => $m->employee_code,
                    'permissions' => $permissions,
                    'supervisor_actions' => collect(PermissionRegistry::SUPERVISOR_ACTIONS)
                        ->filter(fn (string $p) => in_array($p, $permissions, true))->keys()->values()->all(),
                    'max_discount_percent' => number_format((float) $max, 2, '.', ''),
                ];
            })
            ->filter(fn (array $s) => in_array('pos.transact', $s['permissions'], true) || $s['supervisor_actions'] !== [])
            ->values()
            ->all();
    }
}
