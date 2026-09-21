<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\EndOfDayService;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Sales\Domain\Models\CashMovement;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Sales\Http\Resources\SalesResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Transaksi, shift, tutup hari, dan metode bayar di back-office (FR-POS-04, FR-POS-05, FR-PAY-02, FR-PAY-10). */
class BackofficeSalesController extends Controller
{
    public function __construct(private readonly SalesAccess $access) {}

    public function shifts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in([Shift::OPEN, Shift::CLOSED])],
        ]);
        $query = Shift::query()
            ->with('cashier:id,name')
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['business_date'] ?? null, fn ($q, $v) => $q->where('business_date', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('opened_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), fn (Shift $s) => SalesResources::shift($s));
    }

    public function shift(Request $request, string $shift, ShiftReport $report): JsonResponse
    {
        $model = $this->findShift($request, $shift);

        return ApiResponse::ok(SalesResources::shift($model) + [
            'report' => $model->summary ?? $report->build($model),
            'cash_movements' => CashMovement::query()->where('shift_id', $model->id)->orderBy('device_created_at')->get()
                ->map(fn (CashMovement $m) => SalesResources::cashMovement($m))->values(),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'shift_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'flagged' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:40'],
        ]);
        $to = $filters['date_to'] ?? now()->format('Y-m-d');
        $from = $filters['date_from'] ?? CarbonImmutable::parse($to)->subDays(30)->format('Y-m-d');

        $query = Order::query()
            ->with('cashier:id,name')
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            // Selalu batasi rentang business_date agar hanya partisi terkait yang dipindai.
            ->whereBetween('business_date', [$from, $to])
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('outlet_id', $v))
            ->when($filters['shift_id'] ?? null, fn ($q, $v) => $q->where('shift_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filters['flagged'] ?? null) !== null, fn ($q) => $request->boolean('flagged')
                ? $q->whereRaw("flags <> '[]'::jsonb")
                : $q->whereRaw("flags = '[]'::jsonb"))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('receipt_no', 'ilike', '%'.addcslashes((string) $v, '%_\\').'%'))
            ->orderByDesc('business_date')
            ->orderByDesc('device_created_at');

        return ApiResponse::paginated($query->paginate($this->perPage($request)), fn (Order $o) => SalesResources::order($o, false));
    }

    public function order(Request $request, string $order): JsonResponse
    {
        abort_unless(Str::isUuid($order), 404);
        $model = Order::query()
            ->with(['items', 'payments', 'discounts', 'refunds', 'cashier:id,name'])
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->findOrFail($order);

        return ApiResponse::ok(SalesResources::order($model));
    }

    public function endOfDay(Request $request, Outlet $outlet, EndOfDayService $service, BusinessCalendar $calendar): JsonResponse
    {
        $user = $this->actor($request);
        if (! ($user->can('pos.end_of_day') || $this->access->canView($user)) || ! app(AccessScope::class)->allowsOutlet($user, $outlet)) {
            throw new AuthorizationException('Anda tidak memiliki akses ke outlet ini.');
        }
        $data = $request->validate(['business_date' => ['nullable', 'date_format:Y-m-d']]);
        $date = isset($data['business_date']) ? CarbonImmutable::parse($data['business_date'], 'UTC') : $calendar->today($outlet);

        $closed = BusinessDay::query()->where('outlet_id', $outlet->id)->where('business_date', $date->format('Y-m-d'))->first();

        return ApiResponse::ok($service->preview($outlet, $date) + [
            'closed_at' => $closed?->closed_at->toIso8601String(),
            'closed_by' => $closed?->closed_by,
            'closed_summary' => $closed?->summary,
        ]);
    }

    public function closeDay(Request $request, Outlet $outlet, EndOfDayService $service): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $day = $service->close($outlet, CarbonImmutable::parse($data['business_date'], 'UTC'), $this->actor($request), $data['note'] ?? null);

        return ApiResponse::created([
            'id' => $day->id,
            'outlet_id' => $day->outlet_id,
            'business_date' => $day->business_date->format('Y-m-d'),
            'closed_at' => $day->closed_at->toIso8601String(),
            'summary' => $day->summary,
        ]);
    }

    public function paymentMethods(Request $request, Outlet $outlet, PaymentMethods $methods): JsonResponse
    {
        $this->authorize('view', $outlet);

        return ApiResponse::ok($methods->forOutlet($outlet)->map(fn (OutletPaymentMethod $m) => $this->method($m))->values());
    }

    public function updatePaymentMethods(Request $request, Outlet $outlet, PaymentMethods $methods, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $outlet);
        $data = $request->validate([
            'methods' => ['required', 'array', 'min:1', 'max:'.count(PaymentMethods::DEFAULTS)],
            'methods.*.method' => ['required', 'distinct', Rule::in(PaymentMethods::codes())],
            'methods.*.label' => ['required', 'string', 'max:40'],
            'methods.*.is_active' => ['required', 'boolean'],
            'methods.*.sort_order' => ['required', 'integer', 'between:0,999'],
            'methods.*.mdr_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'methods.*.mdr_fixed' => ['required', 'numeric', 'between:0,1000000', 'decimal:0,2'],
        ]);

        $rows = $methods->forOutlet($outlet)->keyBy('method');
        $activeAfter = $rows->mapWithKeys(fn (OutletPaymentMethod $m) => [$m->method => $m->is_active])->all();
        foreach ($data['methods'] as $m) {
            $activeAfter[$m['method']] = (bool) $m['is_active'];
        }
        if (! in_array(true, $activeAfter, true)) {
            return ApiResponse::error(422, [['code' => 'NO_ACTIVE_METHOD', 'field' => 'methods', 'message' => 'Minimal satu metode pembayaran harus aktif.']]);
        }

        DB::transaction(function () use ($data, $rows, $audit, $outlet): void {
            foreach ($data['methods'] as $m) {
                /** @var OutletPaymentMethod $row */
                $row = $rows->get($m['method']);
                $before = $row->only(['label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed']);
                $row->fill(array_diff_key($m, ['method' => true]));
                if ($row->isDirty()) {
                    $row->save();
                    $audit->log('outlet.payment_method_updated', $outlet, $before, $row->only(['label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed']), metadata: ['method' => $row->method]);
                }
            }
        });

        return ApiResponse::ok($methods->forOutlet($outlet)->map(fn (OutletPaymentMethod $m) => $this->method($m))->values());
    }

    /** @return array<string, mixed> */
    private function method(OutletPaymentMethod $m): array
    {
        return [
            'method' => $m->method,
            'label' => $m->label,
            'is_active' => $m->is_active,
            'sort_order' => $m->sort_order,
            'mdr_percent' => $m->mdr_percent,
            'mdr_fixed' => $m->mdr_fixed,
            'via_gateway' => in_array($m->method, PaymentMethods::GATEWAY_METHODS, true),
        ];
    }

    private function findShift(Request $request, string $id): Shift
    {
        abort_unless(Str::isUuid($id), 404);

        return Shift::query()
            ->with('cashier:id,name')
            ->whereIn('outlet_id', $this->access->outletIds($this->actor($request)))
            ->findOrFail($id);
    }
}
