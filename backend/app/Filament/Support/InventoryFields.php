<?php

namespace App\Filament\Support;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\UnitConverter;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Inventory\Domain\Models\StockMovement;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;

/** Komponen & label bersama untuk halaman inventory dan pembelian di back-office. */
final class InventoryFields
{
    public const TRANSFER_COLORS = ['in_transit' => 'warning', 'received' => 'success', 'cancelled' => 'gray'];

    public const COUNT_COLORS = ['counting' => 'info', 'submitted' => 'warning', 'approved' => 'success', 'cancelled' => 'gray'];

    public const PO_COLORS = [
        'draft' => 'gray', 'submitted' => 'warning', 'approved' => 'info', 'rejected' => 'danger',
        'partially_received' => 'warning', 'received' => 'success', 'closed' => 'gray', 'cancelled' => 'gray',
    ];

    public static function user(): ?User
    {
        return MenuFields::user();
    }

    public static function access(): InventoryAccess
    {
        return app(InventoryAccess::class);
    }

    public static function canView(): bool
    {
        $user = self::user();

        return $user !== null && self::access()->canView($user);
    }

    public static function canViewPurchasing(): bool
    {
        $user = self::user();

        return $user !== null && self::access()->canViewPurchasing($user);
    }

    public static function canManageMaster(): bool
    {
        $user = self::user();

        return $user !== null && self::access()->canManageMaster($user);
    }

    /** @return list<string> */
    public static function outletIds(bool $purchasing = false): array
    {
        $user = self::user();
        if ($user === null || ! ($purchasing ? self::canViewPurchasing() : self::canView())) {
            return [];
        }

        return self::access()->outletIds($user, $purchasing);
    }

    /**
     * Lokasi aktif yang boleh dikelola user (untuk formulir).
     *
     * @return array<string, string>
     */
    public static function manageableLocations(string $ability = 'stock'): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        $access = self::access();

        return self::locationQuery()
            ->get()
            ->filter(fn (StockLocation $l) => match ($ability) {
                'purchase' => $access->canRequestPurchase($user, $l->outlet),
                'receive' => $access->canReceive($user, $l->outlet),
                default => $access->canManageOutlet($user, $l->outlet),
            })
            ->mapWithKeys(fn (StockLocation $l) => [$l->id => $l->label()])
            ->all();
    }

    /**
     * Semua lokasi aktif di company (tujuan transfer boleh outlet lain).
     *
     * @return array<string, string>
     */
    public static function allLocations(): array
    {
        return self::locationQuery()->get()->mapWithKeys(fn (StockLocation $l) => [$l->id => $l->label()])->all();
    }

    /**
     * Lokasi yang boleh dilihat user (untuk filter).
     *
     * @return array<string, string>
     */
    public static function visibleLocations(bool $purchasing = false): array
    {
        return self::locationQuery()->whereIn('outlet_id', self::outletIds($purchasing))->get()
            ->mapWithKeys(fn (StockLocation $l) => [$l->id => $l->label()])->all();
    }

    /**
     * Outlet aktif yang stoknya boleh dikelola user.
     *
     * @return array<string, string>
     */
    public static function manageableOutlets(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }

        return Outlet::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Outlet $o) => self::access()->canManageOutlet($user, $o))
            ->mapWithKeys(fn (Outlet $o) => [$o->id => "{$o->name} ({$o->code})"])->all();
    }

    /** @return array<string, string> */
    public static function outletOptions(bool $purchasing = false): array
    {
        return Outlet::withTrashed()->whereIn('id', self::outletIds($purchasing))->orderBy('name')->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Outlet $o) => [$o->id => "{$o->name} ({$o->code})"])->all();
    }

    /**
     * Bahan aktif, dikelompokkan per kategori.
     *
     * @return array<string, array<string, string>>
     */
    public static function ingredientOptions(bool $stockOnly = false): array
    {
        $exploded = $stockOnly
            ? Recipe::query()->where('target_type', Recipe::INGREDIENT)->whereHas('lines')->pluck('target_id')->all()
            : [];

        return Ingredient::query()->where('is_active', true)
            ->when($exploded !== [], fn ($q) => $q->whereNotIn('id', $exploded))
            ->orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'code', 'category', 'base_unit'])
            ->groupBy(fn (Ingredient $i) => $i->category ?: 'Tanpa kategori')
            ->map(fn ($group) => $group->mapWithKeys(fn (Ingredient $i) => [$i->id => "{$i->name} ({$i->base_unit})"])->all())
            ->all();
    }

    /** @return array<string, string> */
    public static function unitOptions(?string $ingredientId): array
    {
        if ($ingredientId === null) {
            return [];
        }
        $ingredient = Ingredient::query()->with('units')->find($ingredientId);
        if ($ingredient === null) {
            return [];
        }
        $options = [];
        foreach (app(UnitConverter::class)->options($ingredient) as $name => $factor) {
            $options[$name] = $factor === '1' ? $name : "{$name} (isi {$factor} {$ingredient->base_unit})";
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function supplierOptions(): array
    {
        return Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function baseUnit(?string $ingredientId): ?string
    {
        return $ingredientId === null ? null : Ingredient::withTrashed()->whereKey($ingredientId)->value('base_unit');
    }

    /** Input kuantitas desimal (maks. 4 angka di belakang koma). */
    public static function qty(string $name, string $label, bool $signed = false): TextInput
    {
        return TextInput::make($name)->label($label)
            ->inputMode('decimal')
            ->rules(['decimal:0,4', $signed ? 'regex:/^-?\d+(\.\d+)?$/' : 'regex:/^\d+(\.\d+)?$/', 'max:99999999', $signed ? 'min:-99999999' : 'min:0'])
            ->required();
    }

    /** Nilai desimal untuk isian formulir: "1500.0000" → "1500", "0.2500" → "0.25". */
    public static function plain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    /** Angka kuantitas tanpa nol berlebih: "1500.0000" → "1.500", "0.2500" → "0,25". */
    public static function number(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        $decimal = BigDecimal::of((string) $value)->strippedOfTrailingZeros();
        $scale = max(0, $decimal->getScale());

        return number_format((float) (string) $decimal, $scale, ',', '.');
    }

    public static function qtyWithUnit(string|int|float|null $value, ?string $unit): string
    {
        return self::number($value).($unit !== null ? ' '.$unit : '');
    }

    public static function movementType(?string $type): string
    {
        return StockMovement::TYPES[$type] ?? (string) $type;
    }

    public static function movementColor(?string $type): string
    {
        return match ($type) {
            'receipt', 'transfer_in', 'sale_return' => 'success',
            'waste' => 'danger',
            'count', 'adjustment' => 'warning',
            default => 'gray',
        };
    }

    public static function poStatus(?string $status): string
    {
        return PurchaseOrder::STATUSES[$status] ?? (string) $status;
    }

    /** @return Builder<StockLocation> */
    private static function locationQuery(): Builder
    {
        return StockLocation::query()->with('outlet')
            ->where('is_active', true)
            ->whereHas('outlet', fn ($q) => $q->where('is_active', true))
            ->orderBy('outlet_id')->orderByDesc('is_default')->orderBy('name');
    }
}
