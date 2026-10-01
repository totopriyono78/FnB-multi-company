<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Application\RoleProvisioner;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Console\Command;

/**
 * Menyelaraskan izin dan peran bawaan untuk entitas yang sudah ada. Aman diulang.
 *
 * Perintah ini ada karena peran bawaan hanya dipasang sekali, saat entitas didaftarkan. Setiap kali
 * modul baru menambah izin — `payment.*` milik Kelompok 4, misalnya — entitas yang sudah berjalan
 * tidak mendapatkannya, dan akibatnya tidak terlihat sebagai galat: layarnya hanya "tidak ada" bagi
 * orang yang semestinya berhak. Jadi perintah ini bagian dari langkah rilis, bukan alat darurat.
 *
 * Yang disentuh hanya peran bawaan. Peran buatan pengguna beserta izinnya tidak ikut diubah.
 *
 * Satu hal yang perlu disebutkan apa adanya: izin peran bawaan **dikembalikan ke daftar bawaan**.
 * Bila pemilik pernah menambah izin ke sebuah peran bawaan (hanya pemilik yang boleh), tambahan itu
 * hilang. Itu disengaja — peran bawaan berarti "peran standar platform" — dan entitas yang memang
 * butuh susunan berbeda sebaiknya membuat perannya sendiri, yang tidak pernah disentuh perintah ini.
 */
class SyncRoles extends Command
{
    protected $signature = 'identitas:sinkron-peran {company? : ID company; kosongkan untuk semua}';

    protected $description = 'Menyelaraskan izin dan peran bawaan untuk entitas yang sudah ada';

    public function handle(TenantContext $context, RoleProvisioner $provisioner): int
    {
        /** @var list<string> $ids */
        $ids = $context->runAsSystem(function (): array {
            $query = Company::query();
            $arg = $this->argument('company');
            if (is_string($arg) && $arg !== '') {
                $query->whereKey($arg);
            }

            return $query->pluck('id')->all();
        });

        foreach ($ids as $id) {
            $provisioner->provisionCompany($id);
        }
        $this->info(count($ids).' entitas diselaraskan.');
        $this->line('Izin peran bawaan kembali ke daftar bawaan; peran buatan pengguna tidak diubah.');

        return self::SUCCESS;
    }
}
