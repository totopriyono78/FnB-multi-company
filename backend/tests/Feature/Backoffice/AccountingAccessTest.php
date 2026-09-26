<?php

use Tests\Support\Factory;

/**
 * Siapa yang boleh melihat modul Akuntansi (matriks izin SRS Lampiran 12.1).
 *
 * Keputusan user 26 Sep 2026: menu Akuntansi tidak boleh tampil untuk kasir maupun manajer,
 * meski layarnya masih prototipe. Yang berhak hanya pemegang `accounting.view` — role
 * `finance` (di company/holding maupun yang dicakupkan ke outlet tertentu) dan `owner`.
 *
 * Diuji lewat permintaan HTTP sungguhan, bukan panggilan `canAccess()` langsung: izin spatie
 * memakai team = company, jadi hanya permintaan yang melewati middleware tenant yang
 * mencerminkan perilaku sebenarnya.
 */
const GRUP_AKUNTANSI = 'Akuntansi (Prototipe)';

/** Alamat semua halaman prototipe Akuntansi. */
const HALAMAN_AKUNTANSI = [
    'akuntansi/holding',
    'akuntansi/bagan-akun',
    'akuntansi/jurnal',
    'akuntansi/dokumen-pembayaran',
    'akuntansi/laporan-keuangan',
    'akuntansi/konsolidasi',
    'akuntansi/aset-sewa',
];

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $this->beranda = "/admin/{$this->company->code}";
});

it('menyembunyikan menu Akuntansi dan menolak alamatnya untuk peran tanpa izin', function (string $role) {
    [$user] = Factory::staff($this->company, [$role], [$this->kemang->id]);

    $this->actingAs($user)->get($this->beranda)->assertOk()->assertDontSee(GRUP_AKUNTANSI);

    // Bukan sekadar menu disembunyikan: mengetik alamatnya langsung pun ditolak.
    foreach (HALAMAN_AKUNTANSI as $halaman) {
        $this->actingAs($user)->get("{$this->beranda}/{$halaman}")->assertForbidden();
    }
})->with([
    'kasir' => ['cashier'],
    'manajer outlet' => ['outlet_manager'],
    'manajer brand' => ['brand_manager'],
    'gudang' => ['warehouse'],
    'dapur' => ['kitchen'],
    'admin company' => ['company_admin'],
]);

it('menampilkan menu Akuntansi untuk finance', function () {
    [$finance] = Factory::staff($this->company, ['finance']);

    foreach (HALAMAN_AKUNTANSI as $halaman) {
        // Sidebar ikut dirender di halaman ini, jadi sekalian dipakai memastikan grupnya tampil.
        // (Beranda tidak dipakai: perannya bisa diarahkan ke halaman lain sebagai halaman awal.)
        $this->actingAs($finance)->get("{$this->beranda}/{$halaman}")->assertOk()->assertSee(GRUP_AKUNTANSI);
    }
});

it('menampilkan menu Akuntansi untuk pemilik', function () {
    foreach (HALAMAN_AKUNTANSI as $halaman) {
        $this->actingAs($this->owner)->get("{$this->beranda}/{$halaman}")->assertOk()->assertSee(GRUP_AKUNTANSI);
    }
});

it('tetap memberi akses pada finance yang dicakupkan ke satu outlet', function () {
    // "Finance cabang": role finance dengan cakupan outlet tertentu tetap berhak —
    // yang menentukan izinnya, bukan luas cakupannya.
    [$financeCabang] = Factory::staff($this->company, ['finance'], [$this->kemang->id]);

    $this->actingAs($financeCabang)->get("{$this->beranda}/akuntansi/holding")
        ->assertOk()->assertSee(GRUP_AKUNTANSI);
});

it('menutup seluruh modul saat saklar prototipe dimatikan, bahkan untuk finance', function () {
    config()->set('fnb.prototype_accounting', false);
    [$finance] = Factory::staff($this->company, ['finance']);

    $this->actingAs($finance)->get("{$this->beranda}/akuntansi/holding")->assertForbidden();
});
