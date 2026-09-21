<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Tenancy\Application\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menjalankan request API dalam mode default-deny (role RLS tanpa company)
 * dan membersihkan konteks di akhir request.
 */
class TenantBoundary
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->restrict();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }
}
