<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\AvailabilityService;
use App\Modules\Catalog\Application\PosCatalogBuilder;
use App\Modules\Catalog\Application\QuoteService;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Http\Requests\QuoteRequest;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Simulasi harga (FR-POS-20), snapshot menu POS (FR-DEV-03), dan tandai habis dari POS (FR-MENU-08). */
class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function backoffice(QuoteRequest $request): JsonResponse
    {
        $user = $this->actor($request);
        $outlet = Outlet::query()->findOrFail((string) $request->input('outlet_id'));
        if (! ($user->can('menu.view') || $user->can('menu.manage') || $user->can('pos.transact'))
            || ! app(AccessScope::class)->allowsOutlet($user, $outlet)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke outlet ini.');
        }

        return ApiResponse::ok($this->quotes->quote($outlet, $request->validated()));
    }

    public function pos(QuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $hasManualDiscount = ! empty($data['order_discounts']);
        foreach ($data['lines'] as $line) {
            $hasManualDiscount = $hasManualDiscount || ! empty($line['discounts']);
        }
        if ($hasManualDiscount) {
            // Batas diskon per role & otorisasi supervisor ditegakkan saat transaksi disimpan (Tahap 3).
            $user = $request->user();
            if (! $user instanceof User || ! $user->can('pos.discount')) {
                throw new AuthorizationException('Diskon manual memerlukan kasir yang berwenang.');
            }
        }

        return ApiResponse::ok($this->quotes->quote($this->device($request)->outlet, $request->validated()));
    }

    public function catalog(Request $request, PosCatalogBuilder $builder): JsonResponse
    {
        $device = $this->device($request);
        // Dicatat sebelum snapshot disusun (acuan validasi harga transaksi, ADR 0004).
        Device::query()->whereKey($device->id)->update(['master_pulled_at' => now()]);

        return ApiResponse::ok($builder->build($device->outlet));
    }

    public function soldOut(Request $request, Item $item, AvailabilityService $service): JsonResponse
    {
        $data = $request->validate(['sold_out' => ['required', 'boolean']]);
        $device = $this->device($request);
        $user = $request->user();
        if (! $user instanceof User || ! $user->can('menu.sold_out') || ! app(AccessScope::class)->allowsOutlet($user, $device->outlet)) {
            throw new AuthorizationException('Anda tidak berwenang menandai menu habis.');
        }

        $row = $service->set($item, $device->outlet, null, (bool) $data['sold_out'], $user);

        return ApiResponse::ok(['item_id' => $item->id, 'outlet_id' => $device->outlet_id, 'is_sold_out' => $row->is_sold_out]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return $device->loadMissing('outlet');
    }
}
