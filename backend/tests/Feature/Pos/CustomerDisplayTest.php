<?php

use Illuminate\Support\Facades\Route;

/**
 * Layar pelanggan (rute /pos/display).
 *
 * Uji di sini menjaga satu janji arsitektural, bukan sekadar "halamannya terbuka": halaman ini
 * TIDAK PERNAH memanggil server. Seluruh isinya dikirim jendela kasir lewat BroadcastChannel,
 * kanal antar-jendela dalam satu peramban. Selama janji itu dipegang, rute ini tidak bisa
 * membocorkan data tenant siapa pun — tidak ada kueri untuk dibocorkan — sehingga ia boleh
 * terbuka tanpa token perangkat.
 *
 * Kalau kelak ada yang menambahkan `fetch` ke halaman ini, uji "tidak memanggil server" akan
 * merah, dan itu memang sinyal yang benar: begitu halaman ini meminta data, ia butuh pairing
 * perangkat dan uji akses lintas tenant seperti KDS.
 */
it('membuka layar pelanggan tanpa login maupun pairing', function () {
    $this->get('/pos/display')
        ->assertOk()
        ->assertSee('Layar Pelanggan', escape: false)
        ->assertSee('fnb-customer-display');
});

it('memakai nama merek pada judul halaman', function () {
    $this->get('/pos/display')
        ->assertOk()
        ->assertSee('Layar Pelanggan — '.config('fnb.brand.name'), escape: false);
});

it('tidak memanggil server sama sekali', function () {
    // Inilah sebabnya rute ini boleh terbuka. Bila salah satu pola di bawah muncul, halaman
    // ini mulai meminta data dan izinnya harus dipikirkan ulang — bukan polanya yang dihapus.
    $html = $this->get('/pos/display')->assertOk()->getContent();

    expect($html)->not->toContain('fetch(')
        ->not->toContain('XMLHttpRequest')
        ->not->toContain('/api/')
        ->not->toContain('csrf-token')
        ->not->toContain('EventSource');
});

it('tidak menampilkan apa pun milik tenant sebelum kasir menyiarkan', function () {
    // Halaman kosong bukan kelalaian, melainkan rancangannya: tanpa jendela kasir di komputer
    // yang sama, layar ini hanya bisa menyambut. Yang diperiksa: wadah isinya dikirim KOSONG
    // dari server — tidak ada satu pun nilai yang dirender di sisi server untuk bisa bocor.
    $html = $this->get('/pos/display')->assertOk()->getContent();

    expect($html)->toContain('<main id="main"></main>')
        ->toContain('Selamat datang');
});

it('ikut mati bersama layar kasir web', function () {
    // Satu saklar untuk seluruh kasir web: outlet yang memakai aplikasi Flutter saja tidak
    // boleh menyisakan halaman ini terbuka di internet.
    config()->set('fnb.pos_web', false);

    $this->get('/pos/display')->assertNotFound();
});

it('punya nama rute supaya tombol di layar kasir tidak memakai alamat tertulis tangan', function () {
    expect(Route::has('pos.display'))->toBeTrue()
        ->and(route('pos.display', absolute: false))->toBe('/pos/display');
});
