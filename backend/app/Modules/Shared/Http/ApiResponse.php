<?php

namespace App\Modules\Shared\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Format respons standar SRS §7.4: { success, data, meta, errors }.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $paginator = $data->resource;
            $meta['pagination'] = [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return response()->json([
            'success' => true,
            'data' => $data ?? (object) [],
            'meta' => self::meta($meta),
            'errors' => [],
        ], $status);
    }

    /**
     * @template T
     *
     * @param  LengthAwarePaginator<int, T>  $paginator
     * @param  callable(T): array<string, mixed>  $map
     */
    public static function paginated(LengthAwarePaginator $paginator, callable $map): JsonResponse
    {
        return self::ok(array_map($map, $paginator->items()), ['pagination' => [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function created(mixed $data = null, array $meta = []): JsonResponse
    {
        return self::ok($data, $meta, 201);
    }

    /**
     * @param  list<array{code: string, message: string, field?: string}>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(int $status, array $errors, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => self::meta($meta),
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function meta(array $meta): array
    {
        return ['request_id' => request()->attributes->get('request_id')] + $meta;
    }
}
