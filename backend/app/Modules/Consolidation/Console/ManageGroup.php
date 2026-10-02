<?php

namespace App\Modules\Consolidation\Console;

use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Console\Command;

/**
 * Buat grup dan atur keanggotaannya (GRP-01).
 *
 * ## Kenapa ini perintah konsol, bukan layar
 *
 * Menambah entitas ke grup berarti menulis ke baris `companies` milik entitas lain, dan untuk
 * memilihnya dari sebuah dropdown layar itu harus lebih dulu MEMBACA daftar entitas lain. Daftar itu
 * sendiri sudah merupakan kebocoran: entitas holding satu pelanggan tidak boleh tahu nama entitas
 * pelanggan lain, bahkan hanya namanya. Jadi keanggotaan ditentukan dengan menyebut kode entitas,
 * oleh orang yang sudah memegang akses platform — bukan dipilih dari daftar.
 *
 * Konsekuensinya memang: pelanggan tidak bisa menyusun grupnya sendiri lewat layar. Itu harga yang
 * dipilih sadar, dan sekali grupnya tersusun, seluruh pekerjaan konsolidasi berikutnya dilakukan dari
 * layar seperti modul lain.
 */
class ManageGroup extends Command
{
    protected $signature = 'konsolidasi:grup
        {holding : Kode entitas holding}
        {--buat= : Kode grup baru yang akan dibuat}
        {--nama= : Nama grup (dipakai bersama --buat)}
        {--tambah=* : Kode entitas yang ditarik menjadi anggota}
        {--keluarkan=* : Kode entitas yang dikeluarkan dari grup}';

    protected $description = 'Membuat grup holding dan mengatur keanggotaan entitas';

    public function handle(TenantContext $context, GroupService $groups): int
    {
        // Kode company dibuat huruf kecil oleh registrar, tetapi orang mengetiknya bebas.
        $holdingCode = trim((string) $this->argument('holding'));

        $holdingId = $context->runAsSystem(
            fn (): ?string => Company::query()->withoutGlobalScopes()
                ->whereRaw('upper(code) = upper(?)', [$holdingCode])->value('id')
        );
        if (! is_string($holdingId)) {
            $this->error("Entitas holding dengan kode {$holdingCode} tidak ditemukan.");

            return self::FAILURE;
        }

        return $context->runAsTenant($holdingId, function () use ($groups): int {
            try {
                $group = $this->resolveGroup($groups);
            } catch (ConsolidationException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if ($group === null) {
                $this->error('Entitas ini belum memegang grup. Jalankan dengan --buat=KODE --nama="Nama Grup".');

                return self::FAILURE;
            }

            $failed = false;
            foreach ((array) $this->option('tambah') as $code) {
                try {
                    $name = $groups->attach($group, (string) $code);
                    $this->info("Ditambahkan: {$name}");
                } catch (ConsolidationException $e) {
                    $this->error($e->getMessage());
                    $failed = true;
                }
            }
            foreach ((array) $this->option('keluarkan') as $code) {
                try {
                    $name = $groups->detach($group, (string) $code);
                    $this->info("Dikeluarkan: {$name}");
                } catch (ConsolidationException $e) {
                    $this->error($e->getMessage());
                    $failed = true;
                }
            }

            $this->newLine();
            $this->line('Grup '.$group->label().' — anggota:');
            foreach ($groups->members($group) as $member) {
                $this->line('  · '.$member['code'].' — '.$member['name']);
            }

            return $failed ? self::FAILURE : self::SUCCESS;
        });
    }

    private function resolveGroup(GroupService $groups): ?Group
    {
        $code = $this->option('buat');
        if (is_string($code) && $code !== '') {
            $name = (string) ($this->option('nama') ?: $code);
            $group = $groups->create(['code' => $code, 'name' => $name]);
            $this->info('Grup dibuat: '.$group->label());

            return $group;
        }

        return $groups->currentGroup();
    }
}
