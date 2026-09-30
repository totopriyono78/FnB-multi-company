<?php

use App\Modules\Sales\Application\ShiftReport;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\Pos;

/**
 * Angka shift yang dibaca layar kasir (temuan user 1 Okt 2026).
 *
 * Gejalanya: transaksi berhasil dan tercatat `paid` di layar Pesanan, tetapi layar Shift menunjukkan
 * "0 transaksi" dan "Rp0". Sebabnya bukan di data melainkan di NAMA FIELD — POS membaca
 * `report.orders` dan `report.totals.total`, sementara server mengirim `order_count` dan
 * `sales_total`. `?? 0` yang dipasang sebagai jaring pengaman justru mengubah salah ketik menjadi
 * angka nol yang tampak sah.
 *
 * Uji kontrak di bawah menutup seluruh KELAS kesalahan itu, bukan cuma dua field yang terlanjur
 * salah: nama field yang dibaca layar kasir dipungut dari berkasnya sendiri, lalu dituntut ada di
 * respons server.
 *
 * Pesanan uji standar Pos::order() bernilai 78.500.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
    $this->token = $this->pos->login('cashier');
});

afterEach(fn () => app(TenantContext::class)->reset());

function laporanShift(object $test, ?string $shiftId = null): array
{
    return (array) $test->getJson('/api/v1/pos/shifts/'.($shiftId ?? $test->shiftId).'/report', bearer($test->token))
        ->assertOk()->json('data.report');
}

it('melaporkan jumlah transaksi dan total penjualan shift yang sedang berjalan', function () {
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2)));

    expect(laporanShift($this))->toMatchArray([
        'order_count' => 2,
        'sales_total' => '157000.00',
        'refund_total' => '0.00',
    ]);
});

it('menyebut urutan struk terakhir termasuk yang dibatalkan', function () {
    /*
     * Nomor struk tetap terpakai walau transaksinya dibatalkan. Kalau penyelarasan memakai jumlah
     * transaksi yang sah, nomor berikutnya akan mengulang nomor yang sudah ada dan ditolak server.
     */
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    $batal = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2)))['order_id'];
    $this->pos->pushAs('manager', 'order.void', [
        'order_id' => $batal, 'voided_by' => $this->pos->userId('manager'),
        'reason' => 'Salah input menu', 'created_at' => now()->toIso8601String(),
    ]);

    $laporan = laporanShift($this);

    expect($laporan['order_count'])->toBe(1)
        ->and($laporan['last_receipt_seq'])->toBe(2);
});

it('menghitung urutan struk per hari bisnis perangkat, bukan per shift', function () {
    // Satu perangkat boleh membuka-tutup shift beberapa kali dalam sehari; penomoran struk berjalan
    // terus sepanjang hari itu (FR-POS-24).
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2)));

    $this->pos->push('shift.close', [
        'shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'),
        'counted_cash' => '657000', 'closed_at' => now()->toIso8601String(),
        'variance_note' => 'Uji penomoran',
    ]);
    [$shiftBaru] = $this->pos->openShift('cashier', '500000');

    expect(laporanShift($this, $shiftBaru))->toMatchArray([
        'order_count' => 0,          // shift baru memang belum menjual apa pun
        'last_receipt_seq' => 2,     // tetapi nomor struk melanjutkan hari yang sama
    ]);
});

it('tidak menghitung nomor struk milik perangkat lain', function () {
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 5)));

    $tetangga = Pos::setup('Warung Seberang', 'WSB');
    [$shiftLain, $ymdLain] = $tetangga->openShift('cashier', '0');
    $tetangga->push('order', $tetangga->order($shiftLain, $tetangga->receipt($ymdLain, 9)));

    expect(laporanShift($this)['last_receipt_seq'])->toBe(5);
});

it('layar Shift di POS hanya membaca field yang memang dikirim server', function () {
    /*
     * Penjaga kelas kesalahan, bukan penjaga dua field.
     *
     * Nama field dipungut dari `pos/app.blade.php` — bagian yang benar-benar menggambar layar Shift
     * dan yang menyelaraskan nomor struk — lalu dicocokkan dengan kunci yang sungguh ada di respons.
     * Salah ketik atau field yang kelak dihapus dari server akan merah di sini, bukan muncul diam-diam
     * sebagai Rp0 di depan kasir.
     */
    $blade = file_get_contents(resource_path('views/pos/app.blade.php'));
    $potong = function (string $dari, string $sampai) use ($blade): string {
        $a = strpos($blade, $dari);
        expect($a)->not->toBeFalse("penanda '{$dari}' tidak ditemukan — perbarui uji ini bila layarnya ditulis ulang");
        $b = strpos($blade, $sampai, $a);

        return substr($blade, $a, $b - $a);
    };

    $bagian = $potong('async function renderShift(){', "el('refreshShift').onclick")
        .$potong('async function syncSequence(){', 'function nextSeq(');

    /*
     * Komentar dibuang lebih dulu. Tanpa ini penjaganya ikut menangkap nama field yang disebut di
     * catatan perbaikan ("dulu membaca r.orders") dan merah untuk kode yang justru sudah benar —
     * uji yang menghukum dokumentasi adalah uji yang akan dimatikan orang.
     */
    $bagian = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $bagian) ?? '';

    // `const r = d.report`, `const c = r.cash` — keduanya dibaca dengan awalan itu di berkasnya.
    preg_match_all('/\br\.([a-z_]+)/', $bagian, $akar);
    preg_match_all('/\bc\.([a-z_]+)/', $bagian, $kas);
    preg_match_all('/\(d\.report \|\| \{\}\)\.([a-z_]+)/', $bagian, $langsung);

    $dibaca = array_values(array_unique([...$akar[1], ...$langsung[1]]));
    $dibacaKas = array_values(array_unique($kas[1]));
    expect($dibaca)->not->toBeEmpty()->and($dibacaKas)->not->toBeEmpty();

    $shift = $this->pos->tenant(fn () => Shift::query()->findOrFail($this->shiftId));
    $laporan = $this->pos->tenant(fn () => app(ShiftReport::class)->build($shift));

    expect(array_diff($dibaca, array_keys($laporan)))->toBe([], 'layar Shift membaca field yang tidak ada di laporan shift');
    expect(array_diff($dibacaKas, array_keys($laporan['cash'])))->toBe([], 'layar Shift membaca field kas yang tidak ada di laporan shift');
});

it('tetap melaporkan angka yang sama setelah shift ditutup', function () {
    // Shift yang sudah ditutup dilayani dari `shifts.summary`, bukan dihitung ulang. Bentuknya harus
    // sama persis, kalau tidak layar yang sama menampilkan angka berbeda sebelum dan sesudah tutup.
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    $sebelum = laporanShift($this);

    $this->pos->push('shift.close', [
        'shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'),
        'counted_cash' => '578500', 'closed_at' => now()->toIso8601String(),
    ], (string) Str::uuid7());

    $sesudah = laporanShift($this);

    foreach (['order_count', 'sales_total', 'refund_total', 'last_receipt_seq'] as $field) {
        expect($sesudah)->toHaveKey($field);
        expect($sesudah[$field])->toEqual($sebelum[$field], "field {$field} berubah setelah shift ditutup");
    }
});
