<?php

use App\Modules\Reporting\Application\DashboardReport;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Satu shift hanya boleh memuat satu hari bisnis (BR-20; cacat dilaporkan user 30 Sep 2026).
 *
 * Gejalanya: transaksi pagi 30 September muncul di Ringkasan sebagai penjualan KEMARIN, padahal
 * judul Ringkasan sudah menulis 30 September.
 *
 * Sebabnya waktu, bukan zona waktu. Setiap baris keuangan mengambil `business_date` dari shiftnya,
 * dan shift menetapkan tanggal itu sekali saja saat dibuka — tidak ada batas atas selama shift
 * masih terbuka. Shift yang lupa ditutup semalam karena itu menyerap seluruh penjualan pagi
 * berikutnya ke tanggal kemarin, sementara Ringkasan membaca jam sekarang. Keduanya benar menurut
 * acuannya masing-masing, dan angkanya tidak pernah bertemu.
 *
 * Outlet uji KMG memakai zona Asia/Jakarta dengan pergantian hari 00.00, jadi 23.50 dan 00.10
 * berada di dua hari bisnis yang berbeda hanya dengan selisih dua puluh menit.
 */
const MALAM = '2026-09-29 16:50:00';   // 23.50 WIB, hari bisnis 29 September

const DINI_HARI = '2026-09-29 17:10:00'; // 00.10 WIB, hari bisnis 30 September

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(MALAM, 'UTC'));
    $this->pos = Pos::setup();
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier');
});

it('menolak transaksi ketika hari bisnisnya sudah berganti tetapi shiftnya belum ditutup', function () {
    expect($this->ymd)->toBe('260929');

    $this->travelTo(CarbonImmutable::parse(DINI_HARI, 'UTC'));
    $hasil = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

    expect($hasil['status'])->toBe('rejected')
        ->and($hasil['error']['code'])->toBe('BUSINESS_DAY_ROLLED_OVER')
        // Pesannya menyebut kedua tanggal: kasir harus tahu ia sedang berdiri di antara dua hari.
        ->and($hasil['error']['message'])->toContain('30-09-2026')
        ->and($hasil['error']['message'])->toContain('29-09-2026')
        ->and($hasil['error']['details'])->toMatchArray([
            'shift_business_date' => '2026-09-29',
            'current_business_date' => '2026-09-30',
        ]);

    expect(Factory::tenant($this->pos->company, fn () => Order::query()->count()))->toBe(0);
});

it('tetap menerima penjualan larut malam yang memang milik hari bisnis shiftnya', function () {
    // Pukul 23.58 WIB masih hari bisnis 29 September — penolakan di sini akan menghentikan
    // penjualan yang sah, dan itu jauh lebih mahal daripada cacat yang sedang diperbaiki.
    $this->travelTo(CarbonImmutable::parse('2026-09-29 16:58:00', 'UTC'));

    $hasil = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

    expect($hasil['status'])->toBe('accepted');
});

it('menerima kiriman susulan perangkat luring untuk penjualan sebelum pergantian hari', function () {
    /*
     * Dibandingkan terhadap waktu TRANSAKSI, bukan jam server. Perangkat yang jaringannya baru
     * pulih pukul 00.10 tetap boleh mengirim penjualan pukul 23.55 tadi malam.
     */
    $this->travelTo(CarbonImmutable::parse(DINI_HARI, 'UTC'));

    $hasil = $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), [
        'created_at' => CarbonImmutable::parse('2026-09-29 16:55:00', 'UTC')->toIso8601String(),
        'completed_at' => CarbonImmutable::parse('2026-09-29 16:56:00', 'UTC')->toIso8601String(),
        'payments' => [[
            'id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '78500', 'tendered' => '100000',
            'created_at' => CarbonImmutable::parse('2026-09-29 16:56:00', 'UTC')->toIso8601String(),
        ]],
    ]));

    expect($hasil['status'])->toBe('accepted');
});

it('menolak tiket dapur dan pergerakan kas dengan alasan yang sama', function () {
    $this->travelTo(CarbonImmutable::parse(DINI_HARI, 'UTC'));

    $tiket = $this->pos->push('kitchen.send', [
        'order_id' => (string) Str::uuid7(), 'shift_id' => $this->shiftId,
        'sent_by' => $this->pos->userId('cashier'), 'sent_at' => now()->toIso8601String(),
        'lines' => [['id' => (string) Str::uuid7(), 'item_id' => $this->pos->croissant->id, 'name' => 'Croissant', 'qty' => '1.000']],
    ]);
    $kas = $this->pos->push('cash_movement', [
        'shift_id' => $this->shiftId, 'type' => 'in', 'amount' => '50000',
        'reason' => 'Tambah modal', 'created_by' => $this->pos->userId('cashier'),
        'created_at' => now()->toIso8601String(),
    ]);

    expect($tiket['error']['code'])->toBe('BUSINESS_DAY_ROLLED_OVER')
        ->and($kas['error']['code'])->toBe('BUSINESS_DAY_ROLLED_OVER');
});

it('mempertemukan kembali angka Ringkasan dengan transaksi kasir', function () {
    /*
     * Inti keluhannya. Setelah shift ditutup dan shift baru dibuka, transaksi pagi itu masuk ke
     * hari bisnis yang sama dengan yang dibaca Ringkasan — bukan lagi ke "kemarin".
     */
    $this->travelTo(CarbonImmutable::parse(DINI_HARI, 'UTC'));
    $this->pos->push('shift.close', [
        'shift_id' => $this->shiftId, 'closed_by' => $this->pos->userId('cashier'),
        'closed_at' => now()->toIso8601String(), 'counted_cash' => '500000',
    ]);
    /*
     * Dibuka dengan jam sekarang, bukan lewat helper openShift() yang memundurkan satu jam:
     * mundur satu jam dari 00.10 WIB masih hari bisnis kemarin, dan itu justru keadaan yang
     * sedang diuji di sini.
     */
    $shiftBaru = (string) Str::uuid7();
    $buka = $this->pos->push('shift.open', [
        'cashier_id' => $this->pos->userId('cashier'), 'opening_cash' => '300000',
        'opened_at' => now()->toIso8601String(),
    ], $shiftBaru);
    expect($buka['status'])->toBe('accepted');

    $ymdBaru = Factory::tenant($this->pos->company, fn () => Shift::query()
        ->findOrFail($shiftBaru)->business_date->format('ymd'));
    expect($ymdBaru)->toBe('260930');

    // Waktu transaksinya jam sekarang: bawaan helper memundurkan lima menit, dan itu jatuh
    // sebelum shift barunya dibuka.
    $jual = $this->pos->push('order', $this->pos->order($shiftBaru, $this->pos->receipt($ymdBaru, 1), [
        'created_at' => now()->toIso8601String(),
        'completed_at' => now()->toIso8601String(),
        'payments' => [[
            'id' => (string) Str::uuid7(), 'method' => 'cash', 'amount' => '78500',
            'tendered' => '100000', 'created_at' => now()->toIso8601String(),
        ]],
    ]));
    expect($jual['status'])->toBe('accepted');

    $dash = Factory::tenant($this->pos->company, fn () => app(DashboardReport::class)
        ->build(app(ReportAccess::class)->filter(Factory::ownerOf($this->pos->company), ReportAccess::SALES, [])));

    expect($dash['business_date'])->toBe('2026-09-30')
        ->and($dash['today']['order_count'])->toBe(1)
        ->and($dash['yesterday']['order_count'])->toBe(0);
});
