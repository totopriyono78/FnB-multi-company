<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Tenancy\Domain\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi endpoint untuk jenis token tertentu: user | device | pos (user yang login via PIN di device).
 */
class EnsureActorType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();

        // Kemampuan dicek persis (bukan wildcard) agar token back-office tidak bisa dipakai sebagai token POS.
        $abilities = $token !== null && is_array($token->abilities ?? null) ? $token->abilities : [];
        $isUser = $actor instanceof User && in_array('backoffice', $abilities, true);
        $isDevice = $actor instanceof Device && in_array('device', $abilities, true);
        $isPos = $actor instanceof User && in_array('pos', $abilities, true);

        $ok = match ($type) {
            'user' => $isUser,
            'device' => $isDevice,
            'pos' => $isPos,
            'device_or_pos' => $isDevice || $isPos,
            default => false,
        };

        if (! $ok) {
            return ApiResponse::error(403, [[
                'code' => 'WRONG_CLIENT',
                'message' => 'Endpoint ini tidak tersedia untuk jenis login yang digunakan.',
            ]]);
        }

        return $next($request);
    }
}
