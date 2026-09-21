<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Tenancy\Application\TenantContext;

/** Channel penjualan & stasiun dapur bawaan untuk company baru (FR-MENU-06, FR-MENU-14). */
class CatalogProvisioner
{
    /** @var list<array{code: string, name: string, type: string, service_charge_applies: bool}> */
    public const CHANNELS = [
        ['code' => 'dine_in', 'name' => 'Makan di Tempat', 'type' => 'in_store', 'service_charge_applies' => true],
        ['code' => 'take_away', 'name' => 'Bawa Pulang', 'type' => 'in_store', 'service_charge_applies' => false],
        ['code' => 'delivery', 'name' => 'Antar Sendiri', 'type' => 'in_store', 'service_charge_applies' => false],
        ['code' => 'gofood', 'name' => 'GoFood', 'type' => 'aggregator', 'service_charge_applies' => false],
        ['code' => 'grabfood', 'name' => 'GrabFood', 'type' => 'aggregator', 'service_charge_applies' => false],
        ['code' => 'shopeefood', 'name' => 'ShopeeFood', 'type' => 'aggregator', 'service_charge_applies' => false],
        ['code' => 'self_order', 'name' => 'Pesan Mandiri', 'type' => 'online', 'service_charge_applies' => true],
    ];

    /** @var list<array{code: string, name: string}> */
    public const STATIONS = [
        ['code' => 'BAR', 'name' => 'Bar'],
        ['code' => 'KITCHEN', 'name' => 'Dapur'],
        ['code' => 'PASTRY', 'name' => 'Pastry'],
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function provision(string $companyId): void
    {
        $this->context->runAsTenant($companyId, function (): void {
            foreach (self::CHANNELS as $i => $channel) {
                $model = SalesChannel::query()->firstOrNew(['code' => $channel['code']]);
                if (! $model->exists) {
                    $model->fill($channel + ['sort_order' => $i, 'is_active' => true]);
                    $model->is_system = true;
                    $model->save();
                }
            }

            foreach (self::STATIONS as $i => $station) {
                KitchenStation::query()->firstOrCreate(['code' => $station['code']], ['name' => $station['name'], 'sort_order' => $i]);
            }
        });
    }
}
