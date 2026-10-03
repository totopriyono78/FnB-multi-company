<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Tenancy\Application\TenantContext;
use Tests\Support\Factory;

/**
 * Siapa yang boleh melihat modul Akuntansi (matriks izin SRS Lampiran 12.1).
 *
 * Keputusan user 26 Sep 2026: menu Akuntansi tidak boleh tampil untuk kasir maupun manajer. Yang
 * berhak hanya pemegang `accounting.view` — role `finance` (di company/holding maupun yang
 * dicakupkan ke outlet tertentu) dan `owner`.
 *
 * Berkas ini awalnya menjaga layar PROTOTIPE. Prototipenya dipensiunkan 3 Okt 2026 setelah
 * seluruhnya tergantikan layar sungguhan, dan uji ini **diarahkan ke layar sungguhan** alih-alih
 * ikut dihapus: yang diminta user adalah aturan siapa-boleh-lihat, bukan penjagaan atas layar
 * tertentu. Sekarang aturan itu justru dijaga pada halaman yang benar-benar memuat angkanya.
 *
 * Diuji lewat permintaan HTTP sungguhan, bukan panggilan `canAccess()` langsung: izin spatie
 * memakai team = company, jadi hanya permintaan yang melewati middleware tenant yang mencerminkan
 * perilaku sebenarnya.
 */
const GRUP_AKUNTANSI = 'Akuntansi';

/**
 * Alamat seluruh layar Akuntansi.
 *
 * Disebut satu per satu dan bukan dikumpulkan dari daftar rute: layar akuntansi BARU harus membuat
 * uji ini gagal sampai seseorang sengaja menambahkannya di sini, karena menambah layar tanpa
 * memikirkan siapa yang boleh membukanya adalah persis cara izin bocor.
 */
const HALAMAN_AKUNTANSI = [
    'pembukuan/bagan-akun',
    'pembukuan/jurnal',
    'pembukuan/jurnal-berulang',
    'pembukuan/periode',
    'pembukuan/pemetaan-akun',
    'pembukuan/saldo-awal',
    'pembukuan/settlement',
    'pembukuan/buku-besar',
    'pembukuan/neraca-saldo',
    'pembukuan/neraca',
    'pembukuan/laba-rugi',
    'pembukuan/arus-kas',
    'pembukuan/perubahan-ekuitas',
];

beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->kemang = Factory::outlet($this->company, null, ['code' => 'KMG']);
    $this->beranda = "/admin/{$this->company->code}";

    // Bagan akun dipasang supaya layar laporan punya sesuatu untuk dirender; yang diuji di sini
    // kewenangannya, bukan angkanya, tetapi halaman yang meledak karena COA kosong akan
    // mengembalikan 500 dan membuat uji ini lulus/gagal karena alasan yang salah.
    Factory::tenant($this->company, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

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

    $this->actingAs($financeCabang)->get("{$this->beranda}/pembukuan/neraca")
        ->assertOk()->assertSee(GRUP_AKUNTANSI);
});

it('tidak menyisakan satu pun alamat layar prototipe', function () {
    // Prototipe dipensiunkan 3 Okt 2026. Alamatnya harus benar-benar hilang, bukan sekadar
    // menunya: layar yang masih bisa dibuka dengan mengetik alamatnya akan memperlihatkan angka
    // CONTOH kepada finance yang menyangka sedang membaca angka sungguhan — dan itu lebih
    // berbahaya daripada layar yang tidak ada.
    [$finance] = Factory::staff($this->company, ['finance']);

    foreach ([
        'akuntansi/holding',
        'akuntansi/bagan-akun',
        'akuntansi/jurnal',
        'akuntansi/dokumen-pembayaran',
        'akuntansi/laporan-keuangan',
        'akuntansi/konsolidasi',
        'akuntansi/aset-sewa',
    ] as $lama) {
        $this->actingAs($finance)->get("{$this->beranda}/{$lama}")->assertNotFound();
    }
});
