<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Application\CatalogProvisioner;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Console\Command;

/** Menyiapkan channel penjualan & stasiun dapur bawaan untuk company yang sudah ada. Aman diulang. */
class ProvisionCatalog extends Command
{
    protected $signature = 'fnb:provision-catalog {company? : ID company; kosongkan untuk semua}';

    protected $description = 'Menyiapkan channel penjualan dan stasiun dapur bawaan';

    public function handle(TenantContext $context, CatalogProvisioner $provisioner): int
    {
        /** @var list<string> $ids */
        $ids = $context->runAsSystem(function (): array {
            $query = Company::query();
            if ($this->argument('company')) {
                $query->whereKey($this->argument('company'));
            }

            return $query->pluck('id')->all();
        });

        foreach ($ids as $id) {
            $provisioner->provision($id);
        }
        $this->info(count($ids).' company disiapkan.');

        return self::SUCCESS;
    }
}
