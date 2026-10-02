<?php

namespace App\Filament\Pages\Treasury;

use App\Filament\Pages\Accounting\AccountingReportPage;
use App\Filament\Support\TreasuryAccess;

/**
 * Induk halaman laporan kas, hutang & piutang.
 *
 * Mewarisi halaman laporan akuntansi karena mesinnya memang sama persis — satu `ReportTable` yang
 * dipakai layar, Excel, dan PDF (ADR 0006), berikut tombol ekspornya. Yang berbeda hanya dua hal,
 * dan keduanya ditimpa di sini: siapa yang boleh membukanya, dan di kelompok menu mana ia muncul.
 * Menyalin seluruh halamannya hanya untuk dua perbedaan itu berarti dua tempat yang harus diperbaiki
 * setiap kali ekspor berubah.
 */
abstract class TreasuryReportPage extends AccountingReportPage
{
    protected static ?string $navigationGroup = 'Kas & Hutang';

    public static function canAccess(): bool
    {
        return TreasuryAccess::canView();
    }
}
