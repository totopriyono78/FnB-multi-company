<?php

use App\Modules\Inventory\Application\InventoryException;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Shared\Domain\Exceptions\ConflictException;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Http\Middleware\TenantBoundary;
use App\Modules\Tenancy\Http\Middleware\EnsureActorType;
use App\Modules\Tenancy\Http\Middleware\EnsureSubscriptionWritable;
use App\Modules\Tenancy\Http\Middleware\ResolveCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Di PaaS (Railway, Fly, Heroku) TLS diterminasi di proxy tepi dan kontainer
         * hanya bisa dihubungi lewat proxy itu, sehingga X-Forwarded-* dapat dipercaya.
         * Tanpa ini Laravel menganggap request http:// dan Filament/Livewire memuat aset
         * dengan skema salah (mixed content) serta IP klien tercatat sebagai IP proxy.
         * Di lokal tidak berpengaruh karena request tidak membawa header X-Forwarded-*.
         */
        $middleware->trustProxies(at: '*');

        $middleware->prepend(AssignRequestId::class);
        $middleware->prependToGroup('api', TenantBoundary::class);
        $middleware->alias([
            'tenant' => ResolveCompany::class,
            'actor' => EnsureActorType::class,
            'writable' => EnsureSubscriptionWritable::class,
        ]);
        $middleware->priority([
            AssignRequestId::class,
            TenantBoundary::class,
            Authenticate::class,
            EnsureActorType::class,
            ResolveCompany::class,
            EnsureSubscriptionWritable::class,
            ThrottleRequests::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'pin', 'pin_confirmation', 'code']);

        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = ['code' => 'VALIDATION_ERROR', 'field' => $field, 'message' => $message];
                }
            }

            return ApiResponse::error($e->status, $errors);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error(401, [['code' => 'UNAUTHENTICATED', 'message' => 'Sesi berakhir. Silakan login kembali.']])
                : null;
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $message = $e->getMessage();
            if ($message === '' || $message === 'This action is unauthorized.') {
                $message = 'Anda tidak memiliki izin untuk aksi ini.';
            }

            return ApiResponse::error(403, [['code' => 'FORBIDDEN', 'message' => $message]]);
        });

        $exceptions->render(function (NotFoundHttpException|ModelNotFoundException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error(404, [['code' => 'NOT_FOUND', 'message' => 'Data tidak ditemukan.']])
                : null;
        });

        $exceptions->render(function (InventoryException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error($e->status, [array_filter([
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'field' => $e->field,
                    'details' => $e->details === [] ? null : $e->details,
                ], fn ($v) => $v !== null)])
                : null;
        });

        $exceptions->render(function (ConflictException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error(409, [['code' => $e->errorCode, 'message' => $e->getMessage()]])
                : null;
        });

        $exceptions->render(function (SalesException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error($e->status, [array_filter([
                    'code' => $e->errorCode,
                    'message' => $e->getMessage(),
                    'field' => $e->field,
                    'retryable' => $e->retryable,
                    'details' => $e->details === [] ? null : $e->details,
                ], fn ($v) => $v !== null)])
                : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            return $isApi($request)
                ? ApiResponse::error(429, [['code' => 'TOO_MANY_REQUESTS', 'message' => 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.']])
                : null;
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error($e->getStatusCode(), [['code' => 'HTTP_'.$e->getStatusCode(), 'message' => $e->getMessage() ?: 'Permintaan tidak dapat diproses.']]);
            }
            if (config('app.debug')) {
                return null;
            }

            return ApiResponse::error(500, [['code' => 'SERVER_ERROR', 'message' => 'Terjadi gangguan di server. Coba lagi beberapa saat.']]);
        });
    })->create();
