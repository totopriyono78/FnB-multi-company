<?php

use App\Modules\Identity\Application\AccessScope;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

/*
 * AccessScope menyimpan daftar outlet dalam cakupan selama satu request agar pengecekan akses
 * tiap menu navigasi tidak mengulang query yang sama (kinerja back-office).
 */

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->brand = Factory::brand($this->company, ['name' => 'Kopi Tepi Jalan']);
    $this->kemang = Factory::outlet($this->company, $this->brand, ['code' => 'KMG', 'name' => 'Kemang']);
    $this->dago = Factory::outlet($this->company, $this->brand, ['code' => 'DGO', 'name' => 'Dago']);
});

it('memuat daftar outlet sekali per request meski dipanggil berulang', function () {
    Factory::tenant($this->company, function () {
        $scope = app(AccessScope::class);
        $scope->flush();
        $scope->for($this->owner); // cakupan peran dimuat terpisah; yang diukur hanya query outlet

        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (range(1, 5) as $i) {
            $scope->outletIds($this->owner);
            $scope->activeOutletOptions($this->owner);
        }
        $outletQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "outlets"'));

        expect($outletQueries)->toHaveCount(1);
    });
});

it('mengurutkan menurut nama dan hanya menawarkan outlet aktif sebagai pilihan', function () {
    Factory::tenant($this->company, function () {
        $this->dago->forceFill(['is_active' => false])->save();
        $scope = app(AccessScope::class);

        expect($scope->outletIds($this->owner))->toBe([$this->dago->id, $this->kemang->id])
            ->and($scope->activeOutletOptions($this->owner))->toBe([$this->kemang->id => 'Kemang (KMG)']);
    });
});

it('membuang simpanan saat outlet ditambah atau dihapus di tengah request', function () {
    Factory::tenant($this->company, function () {
        $scope = app(AccessScope::class);
        expect($scope->outletIds($this->owner))->toHaveCount(2);

        $baru = Outlet::query()->create(['brand_id' => $this->brand->id, 'code' => 'BSD', 'name' => 'BSD', 'city' => 'Tangerang']);
        expect($scope->outletIds($this->owner))->toContain($baru->id)
            ->and($scope->activeOutletOptions($this->owner))->toHaveKey($baru->id);

        $baru->delete();
        expect($scope->activeOutletOptions($this->owner))->not->toHaveKey($baru->id)
            ->and($scope->outletIds($this->owner))->toContain($baru->id); // riwayat tetap bisa dilaporkan
    });
});

it('menghormati cakupan outlet staf dan tidak tercampur antar-company', function () {
    [$manager] = Factory::staff($this->company, ['outlet_manager'], [$this->kemang->id]);
    [$lain, $pemilikLain] = Factory::company('Kopi Seberang');
    Factory::outlet($lain, null, ['code' => 'SBR', 'name' => 'Seberang']);

    Factory::tenant($this->company, function () use ($manager) {
        $scope = app(AccessScope::class);
        expect($scope->outletIds($manager))->toBe([$this->kemang->id])
            ->and($scope->outletIds($this->owner))->toBe([$this->dago->id, $this->kemang->id]);
    });

    Factory::tenant($lain, function () use ($pemilikLain) {
        $ids = app(AccessScope::class)->outletIds($pemilikLain);
        expect($ids)->toHaveCount(1)
            ->and($ids)->not->toContain($this->kemang->id)
            ->and($ids)->not->toContain($this->dago->id);
    });
});
