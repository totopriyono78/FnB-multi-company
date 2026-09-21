<?php

namespace App\Modules\Shared\Http;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use LogicException;

abstract class Controller
{
    use AuthorizesRequests;

    protected function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new LogicException('Endpoint ini membutuhkan login user.');
    }

    protected function companyId(): string
    {
        return app(TenantContext::class)->requireCompanyId();
    }

    protected function perPage(Request $request): int
    {
        return min(max((int) $request->integer('per_page', 25), 1), 100);
    }
}
