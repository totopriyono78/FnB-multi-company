<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\MenuCopier;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Application\MenuSpreadsheet;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Impor/ekspor (FR-MENU-09) dan salin menu (FR-MENU-10). */
class MenuToolsController extends Controller
{
    public function __construct(private readonly MenuScope $scope) {}

    public function export(Request $request, MenuSpreadsheet $sheet): BinaryFileResponse
    {
        $request->validate(['brand_id' => ['required', 'uuid']]);
        $brand = Brand::query()->findOrFail((string) $request->input('brand_id'));
        $user = $this->actor($request);
        if (! ($user->can('menu.view') || $user->can('menu.manage')) || ! $this->scope->allowsBrand($user, $brand->id)) {
            throw new AuthorizationException;
        }

        $path = $sheet->export($brand);
        $name = 'menu-'.Str::slug($brand->name).'-'.now('Asia/Jakarta')->format('Ymd').'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    public function import(Request $request, MenuSpreadsheet $sheet): JsonResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'uuid'],
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ], [], ['file' => 'file menu']);
        $brand = Brand::query()->findOrFail($data['brand_id']);
        $this->ensureManages($request, $brand->id);

        $file = $request->file('file');
        $report = $sheet->import($brand, $file->getRealPath(), $file->getClientOriginalExtension(), $request->boolean('dry_run'));

        return $report['errors'] === []
            ? ApiResponse::ok($report)
            : ApiResponse::error(422, array_map(fn ($e) => ['code' => 'IMPORT_ROW_INVALID', 'field' => 'row.'.$e['row'], 'message' => "Baris {$e['row']}: {$e['message']}"], $report['errors']), ['report' => $report]);
    }

    public function copyBrand(Request $request, MenuCopier $copier): JsonResponse
    {
        $data = $request->validate(['from_brand_id' => ['required', 'uuid'], 'to_brand_id' => ['required', 'uuid']]);
        $from = Brand::query()->findOrFail($data['from_brand_id']);
        $to = Brand::query()->findOrFail($data['to_brand_id']);
        if (! $this->scope->allowsBrand($this->actor($request), $from->id)) {
            throw new AuthorizationException;
        }
        $this->ensureManages($request, $to->id);

        return ApiResponse::ok($copier->copyBrand($from, $to));
    }

    public function copyOutlet(Request $request, MenuCopier $copier): JsonResponse
    {
        $data = $request->validate(['from_outlet_id' => ['required', 'uuid'], 'to_outlet_id' => ['required', 'uuid']]);
        $from = Outlet::query()->findOrFail($data['from_outlet_id']);
        $to = Outlet::query()->findOrFail($data['to_outlet_id']);
        $user = $this->actor($request);
        $access = app(AccessScope::class);
        if (! $access->allowsOutlet($user, $from) || ! $access->allowsOutlet($user, $to)) {
            throw new AuthorizationException;
        }
        $this->ensureManages($request, $to->brand_id);

        return ApiResponse::ok($copier->copyOutlet($from, $to));
    }

    private function ensureManages(Request $request, string $brandId): void
    {
        if (! WritableCompany::allows() || ! $this->scope->canManageBrand($this->actor($request), $brandId)) {
            throw new AuthorizationException('Anda tidak mengelola menu brand ini.');
        }
    }
}
