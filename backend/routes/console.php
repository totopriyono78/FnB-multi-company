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
