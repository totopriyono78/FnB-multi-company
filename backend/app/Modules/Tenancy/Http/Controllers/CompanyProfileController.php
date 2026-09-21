<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Http\Requests\CompanyProfileRequest;
use App\Modules\Tenancy\Http\Resources\CompanyResource;
use Illuminate\Http\JsonResponse;

/** FR-TEN-03 */
class CompanyProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $company = $this->current();
        $this->authorize('view', $company);

        return ApiResponse::ok(new CompanyResource($company->load('plan')));
    }

    public function update(CompanyProfileRequest $request): JsonResponse
    {
        $company = $this->current();
        $this->authorize('update', $company);

        $company->fill($request->validated());
        $company->updated_by = $this->actor($request)->id;
        $company->save();

        return ApiResponse::ok(new CompanyResource($company->load('plan')));
    }

    private function current(): Company
    {
        return Company::query()->findOrFail($this->companyId());
    }
}
