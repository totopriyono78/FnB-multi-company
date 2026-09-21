<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Inventory\Application\FoodCostReport;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Food cost teoritis & aktual (FR-INV-10, FR-RPT-06). */
class FoodCostController extends Controller
{
    public function __construct(
        private readonly InventoryAccess $access,
        private readonly FoodCostReport $report,
    ) {}

    public function menu(Request $request): JsonResponse
    {
        $data = $request->validate(['outlet_id' => ['required', 'uuid']]);
        $outlet = Outlet::query()->find($data['outlet_id']);
        abort_if($outlet === null || ! in_array($outlet->id, $this->access->outletIds($this->actor($request)), true), 404);

        return ApiResponse::ok($this->report->menu($outlet), ['outlet_id' => $outlet->id]);
    }

    public function actual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $to = $data['date_to'] ?? InventoryAccess::today();
        $from = $data['date_from'] ?? CarbonImmutable::parse($to)->startOfMonth()->format('Y-m-d');
        abort_if(CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366, 422, 'Rentang laporan maksimal 1 tahun.');
        $ids = $this->access->outletIds($this->actor($request));
        if (isset($data['outlet_id'])) {
            abort_unless(in_array($data['outlet_id'], $ids, true), 404);
            $ids = [$data['outlet_id']];
        }

        return ApiResponse::ok($this->report->actual($ids, $from, $to));
    }
}
