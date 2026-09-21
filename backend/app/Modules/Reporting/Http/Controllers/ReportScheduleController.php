<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportScheduler;
use App\Modules\Reporting\Domain\Models\ReportDelivery;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jadwal laporan email (FR-RPT-08). */
class ReportScheduleController extends Controller
{
    public function __construct(private readonly ReportScheduler $scheduler) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        abort_unless($this->scheduler->canUse($user) || $this->scheduler->canViewAll($user), 403, 'Anda tidak memiliki akses laporan.');
        $page = ReportSchedule::query()
            ->when(! $this->scheduler->canViewAll($user), fn ($q) => $q->where('created_by', $user->id))
            ->with('owner:id,name')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return ApiResponse::paginated($page, fn (ReportSchedule $s) => $this->present($s));
    }

    public function show(Request $request, string $schedule): JsonResponse
    {
        $model = $this->find($request, $schedule);
        $deliveries = ReportDelivery::query()->where('schedule_id', $model->id)->latest('created_at')->limit(20)->get();

        return ApiResponse::ok($this->present($model) + [
            'deliveries' => $deliveries->map(fn (ReportDelivery $d) => [
                'id' => $d->id,
                'period_from' => $d->period_from->format('Y-m-d'),
                'period_to' => $d->period_to->format('Y-m-d'),
                'status' => $d->status,
                'recipients' => $d->recipients,
                'filename' => $d->filename,
                'row_count' => $d->row_count,
                'error' => $d->error,
                'created_at' => $d->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $model = $this->scheduler->save($this->actor($request), $this->validated($request));

        return ApiResponse::ok($this->present($model->load('owner:id,name')), status: 201);
    }

    public function update(Request $request, string $schedule): JsonResponse
    {
        $model = $this->find($request, $schedule);
        abort_unless($this->scheduler->canEdit($this->actor($request), $model), 403, 'Hanya pembuat jadwal yang dapat mengubahnya.');
        $data = $this->validated($request, $model);
        $model = $this->scheduler->save($this->actor($request), $data, $model);

        return ApiResponse::ok($this->present($model->load('owner:id,name')));
    }

    public function activate(Request $request, string $schedule): JsonResponse
    {
        $model = $this->find($request, $schedule);

        return ApiResponse::ok($this->present($this->scheduler->setActive($this->actor($request), $model, true)));
    }

    public function deactivate(Request $request, string $schedule): JsonResponse
    {
        $model = $this->find($request, $schedule);
        abort_unless($this->scheduler->canToggle($this->actor($request), $model), 403, 'Anda tidak berwenang mengubah jadwal ini.');

        return ApiResponse::ok($this->present($this->scheduler->setActive($this->actor($request), $model, false)));
    }

    private function find(Request $request, string $id): ReportSchedule
    {
        $user = $this->actor($request);
        $model = ReportSchedule::query()->with('owner:id,name')->find($id);
        // Jadwal milik orang lain diperlakukan tidak ada kecuali untuk pengelola company.
        abort_if($model === null || ($model->created_by !== $user->id && ! $this->scheduler->canViewAll($user)), 404, 'Jadwal tidak ditemukan.');

        return $model;
    }

    /**
     * @return array{name: string, report_key: string, format: string, frequency: string, send_time?: string|null, brand_id?: string|null, outlet_id?: string|null, recipients: list<string>|string, is_active?: bool}
     */
    private function validated(Request $request, ?ReportSchedule $current = null): array
    {
        $partial = $current !== null;
        $rule = fn (string $r) => $partial ? ['sometimes', $r] : ['required', $r];
        $data = $request->validate([
            'name' => [...$rule('string'), 'max:100'],
            'report_key' => [...$rule('string'), 'max:40'],
            'format' => [...$rule('string'), 'in:xlsx,pdf'],
            'frequency' => [...$rule('string'), 'in:daily,weekly,monthly'],
            'send_time' => ['nullable', 'date_format:H:i'],
            'brand_id' => ['nullable', 'uuid'],
            'outlet_id' => ['nullable', 'uuid'],
            'recipients' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:'.ReportSchedule::MAX_RECIPIENTS],
            'recipients.*' => ['string', 'email:rfc', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($current !== null) {
            $data += [
                'name' => $current->name,
                'report_key' => $current->report_key,
                'format' => $current->format,
                'frequency' => $current->frequency,
                'send_time' => substr($current->send_time, 0, 5),
                'recipients' => $current->recipients,
                'is_active' => $current->is_active,
            ];
            if (! $request->exists('brand_id')) {
                $data['brand_id'] = $current->brand_id;
            }
            if (! $request->exists('outlet_id')) {
                $data['outlet_id'] = $current->outlet_id;
            }
        }

        /** @var array{name: string, report_key: string, format: string, frequency: string, send_time?: string|null, brand_id?: string|null, outlet_id?: string|null, recipients: list<string>|string, is_active?: bool} $data */
        return $data;
    }

    /** @return array<string, mixed> */
    private function present(ReportSchedule $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'report_key' => $s->report_key,
            'report_label' => ReportCatalog::label($s->report_key),
            'format' => $s->format,
            'frequency' => $s->frequency,
            'send_time' => substr($s->send_time, 0, 5),
            'brand_id' => $s->brand_id,
            'outlet_id' => $s->outlet_id,
            'recipients' => $s->recipients,
            'is_active' => $s->is_active,
            'owner' => $s->owner ? ['id' => $s->owner->id, 'name' => $s->owner->name] : null,
            'next_run_at' => $s->next_run_at?->toIso8601String(),
            'last_run_at' => $s->last_run_at?->toIso8601String(),
            'last_status' => $s->last_status,
            'disabled_reason' => $s->disabled_reason,
        ];
    }
}
