<?php

use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Daftar transaksi shift berjalan untuk panel "Pesanan" di layar kasir
 * (temuan user 30 Sep 2026).
 *
 * Gejalanya: kasir menutup aplikasi POS, memasangkan perangkat ulang, masuk dengan kasir yang
 * sama — dan daftar pesanannya kosong. Transaksinya sendiri tidak pernah hilang; yang hilang
 * adalah satu-satunya jalan kasir menjangkaunya dari layar kasir untuk dicetak ulang, di-void,
 * atau diretur, karena daftar itu hanya hidup di memori peramban.
 *
 * Cakupannya shift, bukan hari bisnis: void dan retur mengubah kas shift, jadi yang boleh
 * ditindaklanjuti kasir harus sama persis dengan isi laporan penutupan shiftnya.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '500000');
});

function buatPesanan(object $test, int $seq): string
{
    return $test->pos->push('order', $test->pos->order($test->shiftId, $test->pos->receipt($test->ymd, $seq)))['order_id'];
}

it('mengembalikan transaksi shift berjalan, yang terbaru lebih dulu', function () {
    $pertama = buatPesanan($this, 1);
    $this->travel(2)->minutes();
    $kedua = buatPesanan($this, 2);

    $response = $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/orders", bearer($this->pos->login('cashier')));

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        // Terbaru di atas: itu yang paling mungkin dicetak ulang atau dibatalkan.
        ->assertJsonPath('data.0.id', $kedua)
        ->assertJsonPath('data.1.id', $pertama)
        ->assertJsonPath('data.0.receipt_no', $this->pos->receipt($this->ymd, 2))
        ->assertJsonPath('data.0.status', 'paid');
    assertStandardEnvelope($response);
});

it('mengembalikan daftar kosong untuk shift yang belum ada transaksinya', function () {
    $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/orders", bearer($this->pos->login('cashier')))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('tidak mencampur transaksi dari shift sebelumnya', function () {
    // Justru inti cakupannya: kas shift baru tidak boleh bisa diubah oleh void transaksi shift lama.
    buatPesanan($this, 1);
    $tutup = $this->pos->push('shift.close', [
        'shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'),
        'closed_at' => now()->toIso8601String(), 'counted_cash' => '0',
        // Hitung buta menuntut keterangan bila kas terhitung berbeda dari kas seharusnya.
        'variance_note' => 'Kas belum dihitung, uji otomatis',
    ]);
    expect($tutup['status'])->toBe('accepted');
    [$shiftBaru, $ymdBaru] = $this->pos->openShift('cashier', '300000');
    $baru = $this->pos->push('order', $this->pos->order($shiftBaru, $this->pos->receipt($ymdBaru, 2)))['order_id'];

    $response = $this->getJson("/api/v1/pos/shifts/{$shiftBaru}/orders", bearer($this->pos->login('cashier')));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $baru);
});

it('menolak shift milik perangkat lain di company yang sama', function () {
    /*
     * Panel ini menyebut dirinya "transaksi perangkat ini", dan itu harus dijaga server:
     * void dari perangkat lain akan merusak kas shift yang bukan miliknya.
     */
    buatPesanan($this, 1);
    [, $tokenLain] = Factory::pairedDevice($this->pos->company, $this->pos->otherOutlet);

    $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/orders", bearer($tokenLain))
        ->assertNotFound();
});

it('menolak shift milik company lain', function () {
    buatPesanan($this, 1);
    $tetangga = Pos::setup('Warung Seberang', 'WSB');

    $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/orders", bearer($tetangga->deviceToken))
        ->assertNotFound();
});

it('menolak permintaan tanpa token sama sekali', function () {
    $this->getJson("/api/v1/pos/shifts/{$this->shiftId}/orders")->assertUnauthorized();
});
