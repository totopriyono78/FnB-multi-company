<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Reporting\Application\DashboardReport;
use App\Modules\Reporting\Application\FraudReport;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Application\ReportFilter;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Laporan, dashboard, dan ekspor (FR-RPT-01..08, FR-RPT-10). */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportAccess $access,
        private readonly ReportCatalog $catalog,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $items = [];
        foreach (ReportCatalog::all() as $key => $meta) {
            if ($this->access->can($user, $meta['kind'])) {
                $items[] = ['key' => $key] + $meta;
            }
        }

        return ApiResponse::ok($items, ['formats' => array_keys(ReportExporter::FORMATS)]);
    }

    public function dashboard(Request $request, DashboardReport $dashboard): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => ['nullable', 'uuid'],
            'outlet_id' => ['nullable', 'uuid'],
        ]);
        $user = $this->actor($request);
        abort_unless($this->access->can($user, ReportAccess::SALES), 403, 'Anda tidak memiliki akses laporan penjualan.');
        $filter = $this->access->filter($user, ReportAccess::SALES, $data);

        return ApiResponse::ok($dashboard->build($filter), ['filter' => $filter->toArray()]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $filter = $this->filter($request, $report);

        return ApiResponse::ok($this->catalog->build($report, $filter)->toArray(), ['filter' => $filter->toArray()]);
    }

    public function events(Request $request, FraudReport $fraud): JsonResponse
    {
        $request->validate(['user_id' => ['nullable', 'uuid']]);
        $filter = $this->filter($request, 'fraud');

        return ApiResponse::ok($fraud->events($filter, $request->input('user_id')), ['filter' => $filter->toArray()]);
    }

    public function export(Request $request, string $report, ReportExporter $exporter): BinaryFileResponse
    {
        $request->validate(['format' => ['required', Rule::in(array_keys(ReportExporter::FORMATS))]]);
        $filter = $this->filter($request, $report);
        $table = $this->catalog->build($report, $filter);
        $company = Company::query()->findOrFail($this->companyId());
        $file = $exporter->export($table, (string) $request->input('format'), $company->name, $filter->from->format('Ymd').'-'.$filter->to->format('Ymd'));

        app(AuditLogger::class)->log('report.exported', null, new: [
            'report' => $report, 'format' => $request->input('format'), 'rows' => count($table->rows),
        ] + $filter->toArray(), userId: $this->actor($request)->id);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']])->deleteFileAfterSend();
    }

    private function filter(Request $request, string $report): ReportFilter
    {
        abort_unless(ReportCatalog::exists($report), 404, 'Laporan tidak ditemukan.');
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'brand_id' => ['nullable', 'uuid'],
            'outlet_id' => ['nullable', 'uuid'],
        ]);
        $user = $this->actor($request);
        $kind = ReportCatalog::kind($report);
        abort_unless($this->access->can($user, $kind), 403, 'Anda tidak memiliki akses ke laporan ini.');

        return $this->access->filter($user, $kind, $data);
    }
}
