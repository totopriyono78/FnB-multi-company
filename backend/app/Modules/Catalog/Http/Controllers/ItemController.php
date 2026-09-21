<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\AvailabilityService;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\ItemPriceHistory;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\OutletItemAvailability;
use App\Modules\Catalog\Http\Requests\AvailabilityRequest;
use App\Modules\Catalog\Http\Requests\ItemPricesRequest;
use App\Modules\Catalog\Http\Requests\ItemRequest;
use App\Modules\Catalog\Http\Resources\CatalogResources;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Shared\Support\Like;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** FR-MENU-02..08, FR-MENU-15 */
class ItemController extends Controller
{
    public function __construct(
        private readonly MenuScope $scope,
        private readonly ItemWriter $writer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Item::class);

        $query = Item::query()->with('category')
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = (string) $request->string('search');
                $q->where(fn ($q) => $q->where('name', 'ilike', Like::contains($term))
                    ->orWhere('short_name', 'ilike', Like::contains($term))
                    ->orWhere('sku', 'ilike', Like::startsWith($term))
                    ->orWhere('barcode', $term));
            })
            ->orderBy('sort_order')->orderBy('name');
        $this->scope->apply($query, $this->actor($request));

        return ApiResponse::paginated($query->paginate($this->perPage($request)), fn (Item $i) => CatalogResources::item($i));
    }

    public function store(ItemRequest $request): JsonResponse
    {
        $this->authorize('create', Item::class);
        $this->ensureManages($request, (string) $request->string('brand_id'));

        $item = $this->writer->save(null, $request->validated());

        return ApiResponse::created(CatalogResources::item($item, true));
    }

    public function show(Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        return ApiResponse::ok(CatalogResources::item($item->load(['category', 'variants', 'modifierGroups', 'bundleGroups.options']), true));
    }

    public function update(ItemRequest $request, Item $item): JsonResponse
    {
        $this->authorize('update', $item);

        return ApiResponse::ok(CatalogResources::item($this->writer->save($item, $request->validated()), true));
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->authorize('delete', $item);
        app(CatalogRemover::class)->item($item);

        return ApiResponse::ok(['id' => $item->id, 'deleted' => true]);
    }

    public function prices(Request $request, Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        $prices = $item->prices()->orderBy('outlet_id')->orderBy('sales_channel_id')->get();
        $scope = app(AccessScope::class)->for($this->actor($request));
        if ($scope !== null) {
            $allowed = Outlet::query()->whereIn('id', $scope['outlets'])->orWhereIn('brand_id', $scope['brands'])->pluck('id')->all();
            $prices = $prices->filter(fn (ItemPrice $p) => $p->outlet_id === null || in_array($p->outlet_id, $allowed, true))->values();
        }

        return ApiResponse::ok($prices->map(CatalogResources::price(...)));
    }

    public function replacePrices(ItemPricesRequest $request, Item $item): JsonResponse
    {
        $this->authorize('update', $item);
        /** @var list<array{item_variant_id?: string|null, outlet_id?: string|null, sales_channel_id?: string|null, price: string}> $rows */
        $rows = $request->validated('prices');

        $variantIds = collect($rows)->pluck('item_variant_id')->filter()->unique();
        if ($variantIds->isNotEmpty() && ItemVariant::query()->where('item_id', $item->id)->whereIn('id', $variantIds)->count() !== $variantIds->count()) {
            throw ValidationException::withMessages(['prices' => 'Varian tidak sesuai dengan menu ini.']);
        }
        $outletIds = collect($rows)->pluck('outlet_id')->filter()->unique();
        if ($outletIds->isNotEmpty() && Outlet::query()->whereIn('id', $outletIds)->where('brand_id', $item->brand_id)->count() !== $outletIds->count()) {
            throw ValidationException::withMessages(['prices' => 'Harga khusus hanya untuk outlet di brand yang sama.']);
        }

        DB::transaction(function () use ($item, $rows): void {
            $existing = $item->prices()->get()->keyBy(fn (ItemPrice $p) => $p->item_variant_id.'|'.$p->outlet_id.'|'.$p->sales_channel_id);
            $keep = [];
            foreach ($rows as $row) {
                $key = ($row['item_variant_id'] ?? '').'|'.($row['outlet_id'] ?? '').'|'.($row['sales_channel_id'] ?? '');
                $price = $existing->get($key) ?? new ItemPrice([
                    'item_id' => $item->id,
                    'item_variant_id' => $row['item_variant_id'] ?? null,
                    'outlet_id' => $row['outlet_id'] ?? null,
                    'sales_channel_id' => $row['sales_channel_id'] ?? null,
                ]);
                $price->price = $row['price'];
                $price->save();
                $keep[] = $price->id;
            }
            $existing->reject(fn (ItemPrice $p) => in_array($p->id, $keep, true))->each->delete();
        });

        return ApiResponse::ok($item->prices()->get()->map(CatalogResources::price(...)));
    }

    public function priceHistory(Request $request, Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        $scope = app(AccessScope::class)->for($this->actor($request));
        $history = ItemPriceHistory::query()->with('changer')->where('item_id', $item->id)
            ->when($scope !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn(
                'outlet_id',
                Outlet::query()->whereIn('id', $scope['outlets'] ?? [])->orWhereIn('brand_id', $scope['brands'] ?? [])->select('id'),
            )))
            ->orderByDesc('created_at')->paginate($this->perPage($request));

        return ApiResponse::paginated($history, fn (ItemPriceHistory $h) => [
            'id' => $h->id,
            'item_variant_id' => $h->item_variant_id,
            'outlet_id' => $h->outlet_id,
            'sales_channel_id' => $h->sales_channel_id,
            'old_price' => $h->old_price === null ? null : (string) $h->old_price,
            'new_price' => $h->new_price === null ? null : (string) $h->new_price,
            'changed_by' => $h->changer ? ['id' => $h->changer->id, 'name' => $h->changer->name] : null,
            'created_at' => $h->created_at->toIso8601String(),
        ]);
    }

    public function availability(Request $request, Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        // Semua outlet brand yang terlihat oleh user; outlet tanpa baris ketersediaan = dijual & tersedia.
        $rows = OutletItemAvailability::query()->where('item_id', $item->id)->get()->keyBy('outlet_id');
        $outlets = app(AccessScope::class)
            ->applyToOutletQuery(Outlet::query()->where('brand_id', $item->brand_id), $this->actor($request))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return ApiResponse::ok($outlets->map(function (Outlet $outlet) use ($rows): array {
            $a = $rows->get($outlet->id);

            return [
                'outlet_id' => $outlet->id, 'outlet_code' => $outlet->code, 'outlet_name' => $outlet->name,
                'is_listed' => $a->is_listed ?? true, 'is_sold_out' => $a->is_sold_out ?? false,
                'sold_out_at' => $a?->sold_out_at?->toIso8601String(),
            ];
        })->values());
    }

    public function setAvailability(AvailabilityRequest $request, Item $item, AvailabilityService $service): JsonResponse
    {
        $this->authorize('view', $item);
        $user = $this->actor($request);
        $outlet = Outlet::query()->findOrFail($request->string('outlet_id'));

        $scopeOk = app(AccessScope::class)->allowsOutlet($user, $outlet);
        $listingChange = $request->has('is_listed');
        if (! $scopeOk
            || ($listingChange && ! $this->scope->canManageBrand($user, $item->brand_id) && ! $user->can('outlet.manage'))
            || ($request->has('is_sold_out') && ! $user->can('menu.sold_out'))) {
            throw new AuthorizationException('Anda tidak berwenang mengubah ketersediaan menu di outlet ini.');
        }

        $row = $service->set(
            $item,
            $outlet,
            $listingChange ? $request->boolean('is_listed') : null,
            $request->has('is_sold_out') ? $request->boolean('is_sold_out') : null,
            $user,
        );

        return ApiResponse::ok(['item_id' => $item->id, 'outlet_id' => $outlet->id, 'is_listed' => $row->is_listed, 'is_sold_out' => $row->is_sold_out]);
    }

    private function ensureManages(Request $request, string $brandId): void
    {
        if (! $this->scope->canManageBrand($this->actor($request), $brandId)) {
            throw new AuthorizationException('Anda tidak mengelola menu brand ini.');
        }
    }
}
