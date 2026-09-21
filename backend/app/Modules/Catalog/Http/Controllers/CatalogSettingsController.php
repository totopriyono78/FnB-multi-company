<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Catalog\Http\Requests\ChannelRequest;
use App\Modules\Catalog\Http\Requests\StationRequest;
use App\Modules\Catalog\Http\Resources\CatalogResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use Illuminate\Http\JsonResponse;

/** Stasiun dapur (FR-MENU-14) dan channel penjualan (FR-MENU-06, BR-01). */
class CatalogSettingsController extends Controller
{
    public function stations(): JsonResponse
    {
        $this->authorize('viewAny', KitchenStation::class);

        return ApiResponse::ok(KitchenStation::query()->orderBy('sort_order')->orderBy('name')->get()->map(CatalogResources::station(...)));
    }

    public function storeStation(StationRequest $request): JsonResponse
    {
        $this->authorize('create', KitchenStation::class);

        return ApiResponse::created(CatalogResources::station(KitchenStation::query()->create($request->validated())->refresh()));
    }

    public function updateStation(StationRequest $request, KitchenStation $station): JsonResponse
    {
        $this->authorize('update', $station);
        $station->update($request->validated());

        return ApiResponse::ok(CatalogResources::station($station));
    }

    public function destroyStation(KitchenStation $station): JsonResponse
    {
        $this->authorize('delete', $station);

        app(CatalogRemover::class)->station($station);

        return ApiResponse::ok(['id' => $station->id, 'deleted' => true]);
    }

    public function channels(): JsonResponse
    {
        $this->authorize('viewAny', SalesChannel::class);

        return ApiResponse::ok(SalesChannel::query()->orderBy('sort_order')->get()->map(CatalogResources::channel(...)));
    }

    public function storeChannel(ChannelRequest $request): JsonResponse
    {
        $this->authorize('create', SalesChannel::class);

        return ApiResponse::created(CatalogResources::channel(SalesChannel::query()->create($request->validated())->refresh()));
    }

    public function updateChannel(ChannelRequest $request, SalesChannel $channel): JsonResponse
    {
        $this->authorize('update', $channel);
        $channel->update($request->validated());

        return ApiResponse::ok(CatalogResources::channel($channel));
    }
}
