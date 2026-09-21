<?php

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Menetapkan request_id untuk log terstruktur dan respons (NFR-OBS-01). */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9\-]{8,40}$/', $incoming) ? $incoming : (string) Str::ulid();

        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
