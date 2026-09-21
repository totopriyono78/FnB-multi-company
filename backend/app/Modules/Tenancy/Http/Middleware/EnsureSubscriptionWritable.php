<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Tenancy\Domain\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mode baca-saja setelah masa tenggang langganan (FR-TEN-08).
 * Endpoint POS tidak memakai middleware ini agar transaksi hari berjalan tidak terblokir.
 */
class EnsureSubscriptionWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->attributes->get('company');

        if ($company instanceof Company && ! $request->isMethodSafe() && $company->isReadOnly()) {
            return ApiResponse::error(402, [[
                'code' => 'SUBSCRIPTION_READ_ONLY',
                'message' => 'Langganan sudah melewati masa tenggang. Data hanya bisa dilihat sampai pembayaran diterima.',
            ]]);
        }

        return $next($request);
    }
}
