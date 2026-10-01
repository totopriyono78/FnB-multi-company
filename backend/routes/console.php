<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Jaring pengaman pemotongan stok penjualan (Tahap 4) & partisi transaksi bulan berikutnya (Tahap 3).
Schedule::command('inventory:post-sales')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('fnb:partitions')->monthlyOn(1, '01:00');

// Laporan terjadwal via email (Tahap 5).
Schedule::command('reports:send-scheduled')->everyFiveMinutes()->withoutOverlapping();

/*
 * Akuntansi (Tahap 8).
 *
 * Keduanya jaring pengaman, bukan jalur utama: jurnal penjualan biasanya sudah lahir saat Tutup
 * Hari, dan jurnal berulang bisa dibuat kapan saja dari layar. Yang dijaga di sini adalah hari
 * ketika jalur utamanya gagal — dan jaring pengaman yang tidak dijadwalkan bukan jaring pengaman.
 */
Schedule::command('akuntansi:jurnal-penjualan')->dailyAt('02:10')->withoutOverlapping();
Schedule::command('akuntansi:jurnal-berulang')->dailyAt('02:20')->withoutOverlapping();
