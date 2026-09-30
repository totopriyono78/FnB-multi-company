<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;

/**
 * Bagan akun standar F&B Indonesia (keputusan user 1 Okt 2026).
 *
 * Empat digit, dikelompokkan 1 Aset · 2 Liabilitas · 3 Ekuitas · 4 Pendapatan · 5 HPP · 6 Beban.
 * Akun berkode kelipatan 100 adalah induk dan tidak bisa dijurnal; yang dijurnal hanya daunnya.
 *
 * Yang membuat daftar ini bukan sekadar contoh: **setiap angka yang lahir dari POS punya tujuannya
 * di sini** — pendapatan per jenis, PB1 terutang, service charge, diskon, pembulatan, retur, kas
 * laci, piutang settlement kartu dan QRIS, biaya MDR, dan selisih kas. Tanpa itu jurnal otomatis
 * (ACC-10/11) tidak punya tempat mendarat dan pemetaannya akan dikarang belakangan.
 *
 * Akun bertanda `system` diacu pemetaan jurnal otomatis: boleh dinonaktifkan atau diganti namanya,
 * tidak boleh dihapus.
 */
final class AccountTemplate
{
    /**
     * @return list<array{code: string, name: string, type: string, parent: string|null, postable: bool, system?: bool, normal?: string}>
     */
    public static function accounts(): array
    {
        return [
            // ---------- 1 ASET ----------
            self::head('1000', 'Aset', Account::ASSET),
            self::head('1100', 'Kas & Setara Kas', Account::ASSET, '1000'),
            self::leaf('1101', 'Kas di Laci Kasir', Account::ASSET, '1100', system: true),
            self::leaf('1102', 'Kas Kecil', Account::ASSET, '1100'),
            self::leaf('1110', 'Bank', Account::ASSET, '1100', system: true),
            self::head('1200', 'Piutang', Account::ASSET, '1000'),
            // Uang non-tunai belum masuk rekening pada hari transaksi; ia piutang sampai cair.
            self::leaf('1201', 'Piutang Settlement Kartu', Account::ASSET, '1200', system: true),
            self::leaf('1202', 'Piutang Settlement QRIS & E-Wallet', Account::ASSET, '1200', system: true),
            self::leaf('1210', 'Piutang Usaha', Account::ASSET, '1200'),
            self::head('1300', 'Persediaan', Account::ASSET, '1000'),
            self::leaf('1301', 'Persediaan Bahan Baku', Account::ASSET, '1300'),
            self::head('1400', 'Biaya Dibayar Dimuka', Account::ASSET, '1000'),
            self::leaf('1401', 'Sewa Dibayar Dimuka', Account::ASSET, '1400'),
            self::head('1500', 'Aset Tetap', Account::ASSET, '1000'),
            self::leaf('1501', 'Peralatan & Perlengkapan', Account::ASSET, '1500'),
            self::leaf('1502', 'Kendaraan', Account::ASSET, '1500'),
            // Akun lawan (contra): jenisnya aset, tetapi saldo normalnya kredit karena ia mengurangi aset.
            self::leaf('1590', 'Akumulasi Penyusutan', Account::ASSET, '1500', normal: 'credit'),

            // ---------- 2 LIABILITAS ----------
            self::head('2000', 'Liabilitas', Account::LIABILITY),
            self::head('2100', 'Utang Jangka Pendek', Account::LIABILITY, '2000'),
            self::leaf('2101', 'Utang Usaha', Account::LIABILITY, '2100'),
            self::leaf('2102', 'Utang Gaji', Account::LIABILITY, '2100'),
            self::head('2200', 'Utang Pajak', Account::LIABILITY, '2000'),
            self::leaf('2201', 'PB1 / Pajak Restoran Terutang', Account::LIABILITY, '2200', system: true),
            self::leaf('2202', 'PPN Keluaran', Account::LIABILITY, '2200', system: true),
            self::leaf('2203', 'PPh Terutang', Account::LIABILITY, '2200'),
            self::head('2300', 'Liabilitas Jangka Panjang', Account::LIABILITY, '2000'),
            self::leaf('2301', 'Utang Pembiayaan / Leasing', Account::LIABILITY, '2300'),

            // ---------- 3 EKUITAS ----------
            self::head('3000', 'Ekuitas', Account::EQUITY),
            self::leaf('3101', 'Modal Disetor', Account::EQUITY, '3000'),
            self::leaf('3201', 'Laba Ditahan', Account::EQUITY, '3000', system: true),
            self::leaf('3202', 'Prive / Pengambilan Pemilik', Account::EQUITY, '3000', normal: 'debit'),

            // ---------- 4 PENDAPATAN ----------
            self::head('4000', 'Pendapatan', Account::REVENUE),
            self::head('4100', 'Penjualan', Account::REVENUE, '4000'),
            self::leaf('4101', 'Penjualan Makanan', Account::REVENUE, '4100', system: true),
            self::leaf('4102', 'Penjualan Minuman', Account::REVENUE, '4100', system: true),
            self::leaf('4103', 'Penjualan Lainnya', Account::REVENUE, '4100', system: true),
            self::leaf('4201', 'Service Charge', Account::REVENUE, '4000', system: true),
            self::head('4300', 'Pengurang Penjualan', Account::REVENUE, '4000'),
            // Tiga akun lawan pendapatan: saldo normalnya debit karena mengurangi penjualan.
            self::leaf('4301', 'Diskon Penjualan', Account::REVENUE, '4300', normal: 'debit', system: true),
            self::leaf('4302', 'Retur Penjualan', Account::REVENUE, '4300', normal: 'debit', system: true),
            // Pembulatan Rp100 bisa menambah atau mengurangi; ditaruh terpisah agar terlihat besarnya.
            self::leaf('4303', 'Selisih Pembulatan Penjualan', Account::REVENUE, '4300', normal: 'debit', system: true),

            // ---------- 5 HARGA POKOK PENJUALAN ----------
            self::head('5000', 'Harga Pokok Penjualan', Account::COGS),
            self::leaf('5101', 'HPP Bahan Baku', Account::COGS, '5000'),
            self::leaf('5102', 'Selisih & Susut Persediaan', Account::COGS, '5000'),

            // ---------- 6 BEBAN ----------
            self::head('6000', 'Beban Operasional', Account::EXPENSE),
            self::leaf('6101', 'Beban Gaji & Tunjangan', Account::EXPENSE, '6000'),
            self::leaf('6102', 'Beban Sewa', Account::EXPENSE, '6000'),
            self::leaf('6103', 'Beban Listrik, Air & Gas', Account::EXPENSE, '6000'),
            self::leaf('6104', 'Beban Penyusutan', Account::EXPENSE, '6000'),
            // Potongan penyedia pembayaran atas tiap transaksi non-tunai.
            self::leaf('6105', 'Beban Biaya Transaksi (MDR)', Account::EXPENSE, '6000', system: true),
            // Selisih laci saat tutup shift: lebih maupun kurang, supaya tidak disembunyikan.
            self::leaf('6106', 'Selisih Kas', Account::EXPENSE, '6000', system: true),
            self::leaf('6107', 'Beban Pemasaran', Account::EXPENSE, '6000'),
            self::leaf('6108', 'Beban Perlengkapan & Pemeliharaan', Account::EXPENSE, '6000'),
            self::leaf('6199', 'Beban Lain-lain', Account::EXPENSE, '6000'),
        ];
    }

    /** @return array{code: string, name: string, type: string, parent: string|null, postable: bool, system: bool, normal: string|null} */
    private static function head(string $code, string $name, string $type, ?string $parent = null): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'postable' => false, 'system' => false, 'normal' => null];
    }

    /** @return array{code: string, name: string, type: string, parent: string|null, postable: bool, system: bool, normal: string|null} */
    private static function leaf(string $code, string $name, string $type, ?string $parent = null, ?string $normal = null, bool $system = false): array
    {
        return ['code' => $code, 'name' => $name, 'type' => $type, 'parent' => $parent, 'postable' => true, 'system' => $system, 'normal' => $normal];
    }
}
