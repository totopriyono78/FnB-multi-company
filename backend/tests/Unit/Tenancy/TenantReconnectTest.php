<?php

use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Di luar transaksi RefreshDatabase: koneksi yang dibuat ulang (mis. setelah terputus) harus
 * kembali memakai role RLS dan company aktif, bukan role pemilik tanpa pembatasan.
 */
it('menerapkan ulang role RLS setelah koneksi dibuat ulang', function () {
    $context = app(TenantContext::class);
    $companyId = (string) Str::uuid7();

    try {
        $context->setTenant($companyId);
        expect(DB::selectOne('select current_user as u')->u)->toBe('fnb_app');

        DB::reconnect();

        expect(DB::selectOne('select current_user as u')->u)->toBe('fnb_app')
            ->and(DB::selectOne("select current_setting('app.current_company_id', true) as c")->c)->toBe($companyId);
    } finally {
        $context->reset();
    }

    expect(DB::selectOne('select current_user as u')->u)->not->toBe('fnb_app');
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Hanya PostgreSQL');
