<?php

namespace App\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Menyalakan isolasi tenant (global scope + RLS + team permission) untuk back-office Filament. */
class SetFilamentTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            abort(404);
        }

        $this->context->setTenant($tenant->id);
        $request->attributes->set('company', $tenant);

        $user = $request->user();
        $membership = $user instanceof User ? $user->membershipFor($tenant->id) : null;
        $authAt = (int) $request->session()->get('fnb_auth_at', 0);
        if ($membership?->sessions_revoked_at !== null && $authAt <= $membership->sessions_revoked_at->getTimestamp()) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->to(Filament::getLoginUrl());
        }

        if ($user !== null && $user->last_company_id !== $tenant->id) {
            $this->context->runAsSystem(fn () => $user->forceFill(['last_company_id' => $tenant->id])->saveQuietly());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }
}
