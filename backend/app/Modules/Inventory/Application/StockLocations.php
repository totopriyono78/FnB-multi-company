<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\Models\StockLocation;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Lokasi stok bawaan & penentuan lokasi pemotongan stok penjualan (FR-INV-02). */
class StockLocations
{
    /** @var array<string, StockLocation> */
    private array $defaults = [];

    /** @var array<string, array<string, StockLocation>> */
    private array $stations = [];

    public function ensureDefault(Outlet $outlet): StockLocation
    {
        $existing = StockLocation::query()->where('outlet_id', $outlet->id)->where('is_default', true)->first();
        if ($existing !== null) {
            return $existing;
        }
        $location = new StockLocation([
            'outlet_id' => $outlet->id,
            'code' => StockLocation::query()->where('outlet_id', $outlet->id)->where('code', 'UTAMA')->exists() ? 'UTAMA-'.random_int(10, 99) : 'UTAMA',
            'name' => 'Gudang Utama',
            'is_default' => true,
        ]);
        $location->company_id = $outlet->company_id;
        $location->save();

        return $location;
    }

    /**
     * Simpan lokasi (API & back-office): lokasi utama selalu aktif dan hanya satu per outlet.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(StockLocation $location, array $data): StockLocation
    {
        if ($location->exists && $location->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
            throw new InventoryException('DEFAULT_LOCATION', 'Jadikan lokasi lain sebagai lokasi utama terlebih dahulu.', 422, 'is_default');
        }
        if ($location->exists && $location->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new InventoryException('DEFAULT_LOCATION', 'Lokasi utama tidak dapat dinonaktifkan. Jadikan lokasi lain sebagai lokasi utama terlebih dahulu.', 422, 'is_active');
        }

        try {
            return DB::transaction(function () use ($location, $data): StockLocation {
                if ((bool) ($data['is_default'] ?? false)) {
                    StockLocation::query()->where('outlet_id', $location->outlet_id)->where('is_default', true)
                        ->when($location->exists, fn ($q) => $q->whereKeyNot($location->id))
                        ->get()->each(fn (StockLocation $l) => $l->update(['is_default' => false]));
                    $data['is_default'] = true;
                    $data['is_active'] = true;
                }
                $location->fill($data)->save();

                return $location;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InventoryException('LOCATION_CONFLICT', 'Kode lokasi atau stasiun dapur sudah dipakai lokasi lain di outlet ini.', 422, 'code');
        }
    }

    /** Lokasi yang dipotong untuk baris penjualan: lokasi stasiun dapur bila dipetakan, selain itu lokasi utama. */
    public function forSale(Outlet $outlet, ?string $kitchenStationId): StockLocation
    {
        if (! isset($this->stations[$outlet->id])) {
            $this->stations[$outlet->id] = StockLocation::query()
                ->where('outlet_id', $outlet->id)
                ->where('is_active', true)
                ->whereNotNull('kitchen_station_id')
                ->get()
                ->keyBy('kitchen_station_id')
                ->all();
        }
        if ($kitchenStationId !== null && isset($this->stations[$outlet->id][$kitchenStationId])) {
            return $this->stations[$outlet->id][$kitchenStationId];
        }

        return $this->defaults[$outlet->id] ??= $this->ensureDefault($outlet);
    }
}
