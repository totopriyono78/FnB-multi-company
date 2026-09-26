<?php

namespace App\Filament\Prototype;

/**
 * Data contoh untuk layar PROTOTIPE modul Akuntansi & Konsolidasi.
 *
 * Seluruh angka di sini konsisten satu sama lain (neraca seimbang, laba rugi
 * menjumlah ke konsolidasi) supaya dapat didemokan tanpa terlihat asal.
 * Tidak ada yang disimpan ke database — ini murni lapisan tampilan.
 */
class DemoBooks
{
    public const PERIODE = '1 - 30 September 2026';

    public const HOLDING = 'PT Nusantara Boga Group';

    /** @return array<string, array{name: string, short: string, kind: string, city: string}> */
    public static function entities(): array
    {
        return [
            'A' => ['name' => 'PT Kemang Rasa Utama', 'short' => 'Resto Kemang', 'kind' => 'F&B', 'city' => 'Jakarta'],
            'B' => ['name' => 'PT Dago Boga Sejahtera', 'short' => 'Resto Dago', 'kind' => 'F&B', 'city' => 'Bandung'],
            'C' => ['name' => 'PT Villa Ubud Asri', 'short' => 'Villa Ubud', 'kind' => 'Villa', 'city' => 'Bali'],
            'D' => ['name' => 'PT Nusantara Retail Mandiri', 'short' => 'Retail', 'kind' => 'Retail', 'city' => 'Jakarta'],
        ];
    }

    /**
     * Laba rugi per entitas (Rupiah penuh).
     *
     * @return array<string, array<string, int>>
     */
    public static function profitLoss(): array
    {
        return [
            'A' => ['sales' => 1_850_000_000, 'cogs' => 620_000_000, 'payroll' => 310_000_000, 'rent' => 85_000_000, 'utility' => 42_000_000, 'other' => 78_000_000, 'depreciation' => 35_000_000],
            'B' => ['sales' => 1_240_000_000, 'cogs' => 430_000_000, 'payroll' => 236_000_000, 'rent' => 60_000_000, 'utility' => 28_000_000, 'other' => 54_000_000, 'depreciation' => 24_000_000],
            'C' => ['sales' => 980_000_000, 'cogs' => 210_000_000, 'payroll' => 180_000_000, 'rent' => 0, 'utility' => 46_000_000, 'other' => 92_000_000, 'depreciation' => 118_000_000],
            'D' => ['sales' => 760_000_000, 'cogs' => 520_000_000, 'payroll' => 96_000_000, 'rent' => 36_000_000, 'utility' => 14_000_000, 'other' => 28_000_000, 'depreciation' => 12_000_000],
        ];
    }

    /**
     * Neraca per entitas (Rupiah penuh).

     *

     * @return array<string, array<string, int>>
     */
    public static function balance(): array
    {
        return [
            'A' => ['cash' => 270_000_000, 'ar' => 65_000_000, 'ar_group' => 150_000_000, 'stock' => 95_000_000, 'fixed' => 1_150_000_000,
                'ap' => 185_000_000, 'ap_group' => 0, 'capital' => 500_000_000, 'retained' => 365_000_000],
            'B' => ['cash' => 260_000_000, 'ar' => 38_000_000, 'ar_group' => 0, 'stock' => 62_000_000, 'fixed' => 780_000_000,
                'ap' => 132_000_000, 'ap_group' => 0, 'capital' => 400_000_000, 'retained' => 200_000_000],
            'C' => ['cash' => 310_000_000, 'ar' => 84_000_000, 'ar_group' => 0, 'stock' => 46_000_000, 'fixed' => 2_480_000_000,
                'ap' => 96_000_000, 'ap_group' => 150_000_000, 'capital' => 1_500_000_000, 'retained' => 840_000_000],
            'D' => ['cash' => 145_000_000, 'ar' => 52_000_000, 'ar_group' => 0, 'stock' => 310_000_000, 'fixed' => 240_000_000,
                'ap' => 218_000_000, 'ap_group' => 0, 'capital' => 300_000_000, 'retained' => 175_000_000],
        ];
    }

    /**
     * Transaksi antar-entitas yang harus dieliminasi.

     *

     * @return list<array<string, mixed>>
     */
    public static function eliminations(): array
    {
        return [
            [
                'label' => 'Piutang / hutang antar-entitas',
                'detail' => 'Talangan biaya renovasi Villa Ubud oleh Kemang Rasa Utama',
                'debit' => 'Hutang Afiliasi (Villa Ubud)',
                'credit' => 'Piutang Afiliasi (Resto Kemang)',
                'amount' => 150_000_000,
                'rows' => ['ar_group', 'ap_group'],
            ],
            [
                'label' => 'Penjualan / pembelian antar-entitas',
                'detail' => 'Katering harian Resto Kemang untuk Villa Ubud (Sep 2026)',
                'debit' => 'Penjualan (Resto Kemang)',
                'credit' => 'Beban Lain-lain (Villa Ubud)',
                'amount' => 75_000_000,
                'rows' => ['sales', 'other'],
            ],
        ];
    }

    /**
     * Bagan akun standar (COA induk holding).

     *

     * @return list<array<string, mixed>>
     */
    public static function chartOfAccounts(): array
    {
        $a = fn (string $code, string $name, int $level, string $type, bool $locked = true, ?string $note = null) => compact('code', 'name', 'level', 'type', 'locked', 'note');

        return [
            $a('1-0000', 'ASET', 0, 'Aset'),
            $a('1-1000', 'Kas & Bank', 1, 'Aset'),
            $a('1-1100', 'Kas Besar', 2, 'Aset'),
            $a('1-1110', 'Kas Kecil Outlet', 2, 'Aset', true, 'Per outlet, dipertanggungjawabkan mingguan'),
            $a('1-1200', 'Bank - Operasional', 2, 'Aset'),
            $a('1-1300', 'Piutang Settlement Non-Tunai', 2, 'Aset', true, 'QRIS/kartu sebelum cair'),
            $a('1-2000', 'Piutang', 1, 'Aset'),
            $a('1-2100', 'Piutang Usaha', 2, 'Aset'),
            $a('1-2200', 'Piutang Afiliasi', 2, 'Aset', true, 'Ditandai untuk eliminasi konsolidasi'),
            $a('1-3000', 'Persediaan', 1, 'Aset'),
            $a('1-3100', 'Persediaan Bahan', 2, 'Aset'),
            $a('1-3200', 'Persediaan Barang Dagang', 2, 'Aset'),
            $a('1-4000', 'Aset Tetap', 1, 'Aset'),
            $a('1-4100', 'Bangunan & Renovasi', 2, 'Aset'),
            $a('1-4200', 'Peralatan Dapur & Outlet', 2, 'Aset'),
            $a('1-4900', 'Akumulasi Penyusutan', 2, 'Aset', true, 'Kontra aset'),
            $a('2-0000', 'LIABILITAS', 0, 'Liabilitas'),
            $a('2-1100', 'Hutang Usaha', 1, 'Liabilitas'),
            $a('2-1200', 'Hutang Afiliasi', 1, 'Liabilitas', true, 'Ditandai untuk eliminasi konsolidasi'),
            $a('2-1300', 'Hutang Pajak (PB1/PPN)', 1, 'Liabilitas'),
            $a('2-1400', 'Hutang Gaji', 1, 'Liabilitas'),
            $a('2-2100', 'Hutang Sewa & Pembiayaan Aset', 1, 'Liabilitas'),
            $a('3-0000', 'EKUITAS', 0, 'Ekuitas'),
            $a('3-1000', 'Modal Disetor', 1, 'Ekuitas'),
            $a('3-2000', 'Laba Ditahan', 1, 'Ekuitas'),
            $a('3-3000', 'Laba Tahun Berjalan', 1, 'Ekuitas'),
            $a('4-0000', 'PENDAPATAN', 0, 'Pendapatan'),
            $a('4-1100', 'Penjualan Makanan', 1, 'Pendapatan'),
            $a('4-1200', 'Penjualan Minuman', 1, 'Pendapatan'),
            $a('4-1900', 'Diskon Penjualan', 1, 'Pendapatan', true, 'Kontra pendapatan'),
            $a('4-2000', 'Pendapatan Kamar', 1, 'Pendapatan', false, 'Khusus entitas villa — sub-akun lokal'),
            $a('5-0000', 'HARGA POKOK PENJUALAN', 0, 'HPP'),
            $a('5-1100', 'HPP Bahan', 1, 'HPP'),
            $a('5-1200', 'HPP Barang Dagang', 1, 'HPP'),
            $a('6-0000', 'BEBAN OPERASIONAL', 0, 'Beban'),
            $a('6-1100', 'Gaji & Tunjangan', 1, 'Beban'),
            $a('6-1200', 'Sewa Tempat', 1, 'Beban'),
            $a('6-1300', 'Listrik, Air & Gas', 1, 'Beban'),
            $a('6-1500', 'MDR & Biaya Bank', 1, 'Beban'),
            $a('6-1900', 'Beban Lain-lain', 1, 'Beban'),
            $a('6-2000', 'Penyusutan', 1, 'Beban'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function journals(): array
    {
        return [
            [
                'no' => 'JU-A-2609-0142', 'date' => '26 Sep 2026', 'entity' => 'A', 'source' => 'Otomatis — Tutup Hari POS',
                'memo' => 'Penjualan Resto Kemang 26 Sep 2026 (POS-01, POS-02)', 'status' => 'Diposting', 'amount' => 68_450_000,
                'lines' => [
                    ['1-1100', 'Kas Besar', 31_200_000, 0],
                    ['1-1300', 'Piutang Settlement Non-Tunai', 37_250_000, 0],
                    ['4-1100', 'Penjualan Makanan', 0, 48_900_000],
                    ['4-1200', 'Penjualan Minuman', 0, 13_400_000],
                    ['4-1900', 'Diskon Penjualan', 1_150_000, 0],
                    ['2-1300', 'Hutang Pajak (PB1 10%)', 0, 7_300_000],
                ],
            ],
            [
                'no' => 'JU-A-2609-0143', 'date' => '26 Sep 2026', 'entity' => 'A', 'source' => 'Otomatis — Pemakaian bahan',
                'memo' => 'HPP bahan terjual 26 Sep 2026', 'status' => 'Diposting', 'amount' => 21_800_000,
                'lines' => [
                    ['5-1100', 'HPP Bahan', 21_800_000, 0],
                    ['1-3100', 'Persediaan Bahan', 0, 21_800_000],
                ],
            ],
            [
                'no' => 'JU-C-2509-0067', 'date' => '25 Sep 2026', 'entity' => 'C', 'source' => 'SPPK-C-2609-0031',
                'memo' => 'Pembelian amenities kamar — CV Bali Supply', 'status' => 'Diposting', 'amount' => 18_400_000,
                'lines' => [
                    ['1-3100', 'Persediaan Bahan', 18_400_000, 0],
                    ['2-1100', 'Hutang Usaha', 0, 18_400_000],
                ],
            ],
            [
                'no' => 'JU-B-2409-0088', 'date' => '24 Sep 2026', 'entity' => 'B', 'source' => 'Manual — diverifikasi pusat',
                'memo' => 'Reklas biaya promosi Agustus ke akun pemasaran', 'status' => 'Menunggu verifikasi', 'amount' => 6_500_000,
                'lines' => [
                    ['6-1900', 'Beban Lain-lain', 0, 6_500_000],
                    ['6-1500', 'MDR & Biaya Bank', 6_500_000, 0],
                ],
            ],
            [
                'no' => 'JU-D-2309-0021', 'date' => '23 Sep 2026', 'entity' => 'D', 'source' => 'Impor rekap penjualan',
                'memo' => 'Penjualan retail 23 Sep 2026 (impor rekap harian)', 'status' => 'Diposting', 'amount' => 24_600_000,
                'lines' => [
                    ['1-1200', 'Bank - Operasional', 24_600_000, 0],
                    ['4-1100', 'Penjualan Barang Dagang', 0, 22_150_000],
                    ['2-1300', 'Hutang Pajak (PPN)', 0, 2_450_000],
                ],
            ],
            [
                'no' => 'JU-A-3009-0170', 'date' => '30 Sep 2026', 'entity' => 'A', 'source' => 'Otomatis — Penyusutan',
                'memo' => 'Penyusutan aset tetap September 2026', 'status' => 'Draft', 'amount' => 35_000_000,
                'lines' => [
                    ['6-2000', 'Penyusutan', 35_000_000, 0],
                    ['1-4900', 'Akumulasi Penyusutan', 0, 35_000_000],
                ],
            ],
            [
                'no' => 'JU-C-3009-0072', 'date' => '30 Sep 2026', 'entity' => 'C', 'source' => 'Otomatis — Akrual sewa',
                'memo' => 'Amortisasi sewa lahan parkir September 2026', 'status' => 'Draft', 'amount' => 9_000_000,
                'lines' => [
                    ['6-1200', 'Sewa Tempat', 9_000_000, 0],
                    ['1-1000', 'Sewa Dibayar Dimuka', 0, 9_000_000],
                ],
            ],
            [
                'no' => 'JU-B-2209-0081', 'date' => '22 Sep 2026', 'entity' => 'B', 'source' => 'Manual',
                'memo' => 'Koreksi salah akun listrik outlet Dago', 'status' => 'Ditolak', 'amount' => 3_200_000,
                'lines' => [
                    ['6-1300', 'Listrik, Air & Gas', 3_200_000, 0],
                    ['6-1900', 'Beban Lain-lain', 0, 3_200_000],
                ],
            ],
        ];
    }

    /**
     * Buku besar contoh: Bank - Operasional entitas A.

     *

     * @return list<array<string, mixed>>
     */
    public static function ledger(): array
    {
        $rows = [
            ['01 Sep 2026', 'Saldo awal', '', 0, 0],
            ['03 Sep 2026', 'Setoran hasil penjualan 1-2 Sep', 'JU-A-0309-0011', 128_400_000, 0],
            ['05 Sep 2026', 'Pembayaran supplier bahan — PT Sumber Segar', 'AB-A-0509-0007', 0, 86_200_000],
            ['08 Sep 2026', 'Pencairan settlement QRIS 1-7 Sep', 'JU-A-0809-0029', 214_650_000, 0],
            ['10 Sep 2026', 'Pembayaran sewa tempat September', 'AB-A-1009-0009', 0, 85_000_000],
            ['15 Sep 2026', 'Setoran hasil penjualan 8-14 Sep', 'JU-A-1509-0058', 196_300_000, 0],
            ['16 Sep 2026', 'Pembayaran gaji periode Sep', 'AB-A-1609-0012', 0, 310_000_000],
            ['20 Sep 2026', 'Talangan renovasi Villa Ubud', 'AB-A-2009-0015', 0, 150_000_000],
            ['22 Sep 2026', 'Pencairan settlement kartu 8-21 Sep', 'JU-A-2209-0101', 178_900_000, 0],
            ['25 Sep 2026', 'Pembayaran listrik & gas', 'AB-A-2509-0018', 0, 42_000_000],
            ['28 Sep 2026', 'Setoran hasil penjualan 15-27 Sep', 'JU-A-2809-0150', 240_950_000, 0],
            ['30 Sep 2026', 'Biaya administrasi & MDR bank', 'JU-A-3009-0168', 0, 15_000_000],
        ];
        $saldo = 186_000_000;
        $out = [];
        foreach ($rows as $i => [$date, $memo, $ref, $debit, $credit]) {
            $saldo += $debit - $credit;
            $out[] = ['date' => $date, 'memo' => $memo, 'ref' => $ref, 'debit' => $debit, 'credit' => $credit, 'balance' => $saldo, 'opening' => $i === 0];
        }
        $out[0]['balance'] = 186_000_000;

        return $out;
    }

    /**
     * Dokumen pembayaran (SPPK / advis bayar / advis tagih).

     *

     * @return list<array<string, mixed>>
     */
    public static function documents(): array
    {
        return [
            [
                'no' => 'SPPK-A-2609-0118', 'type' => 'SPPK', 'entity' => 'A', 'date' => '26 Sep 2026',
                'payee' => 'PT Sumber Segar Nusantara', 'purpose' => 'Pembelian bahan baku minggu ke-4',
                'account' => '5-1100 HPP Bahan', 'amount' => 86_200_000, 'status' => 'Menunggu verifikasi',
                'maker' => 'Siti Rahayu (Finance Kemang)', 'checker' => '-', 'files' => 3, 'due' => '30 Sep 2026',
            ],
            [
                'no' => 'SPPK-C-2609-0031', 'type' => 'SPPK', 'entity' => 'C', 'date' => '25 Sep 2026',
                'payee' => 'CV Bali Supply', 'purpose' => 'Amenities kamar (sabun, sampo, slipper)',
                'account' => '1-3100 Persediaan Bahan', 'amount' => 18_400_000, 'status' => 'Diverifikasi',
                'maker' => 'Pusat — Andi Wijaya', 'checker' => 'Pusat — Rina Lestari', 'files' => 2, 'due' => '02 Okt 2026',
            ],
            [
                'no' => 'AB-A-2509-0018', 'type' => 'Advis bayar', 'entity' => 'A', 'date' => '25 Sep 2026',
                'payee' => 'PLN & Gas Negara', 'purpose' => 'Listrik dan gas September',
                'account' => '6-1300 Listrik, Air & Gas', 'amount' => 42_000_000, 'status' => 'Dibayar',
                'maker' => 'Siti Rahayu (Finance Kemang)', 'checker' => 'Pusat — Rina Lestari', 'files' => 2, 'due' => '25 Sep 2026',
            ],
            [
                'no' => 'AB-B-2409-0026', 'type' => 'Advis bayar', 'entity' => 'B', 'date' => '24 Sep 2026',
                'payee' => 'PT Dago Properti', 'purpose' => 'Sewa tempat kuartal IV',
                'account' => '6-1200 Sewa Tempat', 'amount' => 60_000_000, 'status' => 'Dibayar',
                'maker' => 'Pusat — Andi Wijaya', 'checker' => 'Pusat — Rina Lestari', 'files' => 4, 'due' => '24 Sep 2026',
            ],
            [
                'no' => 'AT-A-2009-0004', 'type' => 'Advis tagih', 'entity' => 'A', 'date' => '20 Sep 2026',
                'payee' => 'PT Villa Ubud Asri (afiliasi)', 'purpose' => 'Talangan renovasi + katering September',
                'account' => '1-2200 Piutang Afiliasi', 'amount' => 225_000_000, 'status' => 'Terkirim',
                'maker' => 'Pusat — Andi Wijaya', 'checker' => 'Pusat — Rina Lestari', 'files' => 1, 'due' => '20 Okt 2026',
            ],
            [
                'no' => 'SPPK-D-2309-0052', 'type' => 'SPPK', 'entity' => 'D', 'date' => '23 Sep 2026',
                'payee' => 'CV Karya Logistik', 'purpose' => 'Ongkos kirim barang dagang',
                'account' => '6-1900 Beban Lain-lain', 'amount' => 7_850_000, 'status' => 'Dikembalikan',
                'maker' => 'Pusat — Andi Wijaya', 'checker' => 'Pusat — Rina Lestari', 'files' => 1, 'due' => '28 Sep 2026',
            ],
            [
                'no' => 'SPPK-B-2209-0047', 'type' => 'SPPK', 'entity' => 'B', 'date' => '22 Sep 2026',
                'payee' => 'Koperasi Karyawan', 'purpose' => 'Pinjaman karyawan (potong gaji)',
                'account' => '6-1100 Gaji & Tunjangan', 'amount' => 12_500_000, 'status' => 'Menunggu verifikasi',
                'maker' => 'Bagus Nugroho (Finance Dago)', 'checker' => '-', 'files' => 2, 'due' => '30 Sep 2026',
            ],
            [
                'no' => 'AB-C-1809-0011', 'type' => 'Advis bayar', 'entity' => 'C', 'date' => '18 Sep 2026',
                'payee' => 'PT Bali Laundry Utama', 'purpose' => 'Laundry linen Agustus',
                'account' => '6-1900 Beban Lain-lain', 'amount' => 14_200_000, 'status' => 'Dibayar',
                'maker' => 'Pusat — Andi Wijaya', 'checker' => 'Pusat — Rina Lestari', 'files' => 3, 'due' => '18 Sep 2026',
            ],
        ];
    }

    /**
     * Jejak persetujuan dokumen pertama.

     *

     * @return list<array{time: string, actor: string, action: string, note: string}>
     */
    public static function documentTrail(): array
    {
        return [
            ['time' => '26 Sep 2026 09.14', 'actor' => 'Siti Rahayu — Finance Resto Kemang', 'action' => 'Dibuat & diajukan', 'note' => '3 lampiran: nota supplier, surat jalan, bukti terima barang'],
            ['time' => '26 Sep 2026 09.15', 'actor' => 'Sistem', 'action' => 'Kode akun diusulkan', 'note' => '5-1100 HPP Bahan (dari riwayat supplier yang sama)'],
            ['time' => '26 Sep 2026 10.02', 'actor' => 'Rina Lestari — Verifikator Pusat', 'action' => 'Dibuka untuk diperiksa', 'note' => 'Menunggu pencocokan dengan PO dan nota fisik'],
            ['time' => '—', 'actor' => 'Menunggu', 'action' => 'Persetujuan tingkat 2', 'note' => 'Nilai di atas Rp 50.000.000 memerlukan persetujuan Manajer Keuangan Grup'],
        ];
    }

    /**
     * Register aset & kontrak sewa.

     *

     * @return array{assets: list<array<string, mixed>>, leases: list<array<string, mixed>>}
     */
    public static function assets(): array
    {
        return [
            'assets' => [
                ['code' => 'AST-A-0007', 'name' => 'Renovasi & interior outlet Kemang', 'entity' => 'A', 'acquired' => '01 Feb 2024', 'cost' => 980_000_000, 'life' => '8 tahun', 'method' => 'Garis lurus', 'monthly' => 10_208_333, 'book' => 638_000_000],
                ['code' => 'AST-A-0012', 'name' => 'Peralatan dapur (kompor, chiller, oven)', 'entity' => 'A', 'acquired' => '15 Mar 2024', 'cost' => 420_000_000, 'life' => '5 tahun', 'method' => 'Garis lurus', 'monthly' => 7_000_000, 'book' => 214_000_000],
                ['code' => 'AST-B-0003', 'name' => 'Renovasi outlet Dago', 'entity' => 'B', 'acquired' => '10 Jun 2024', 'cost' => 640_000_000, 'life' => '8 tahun', 'method' => 'Garis lurus', 'monthly' => 6_666_667, 'book' => 458_000_000],
                ['code' => 'AST-C-0001', 'name' => 'Bangunan villa (6 unit)', 'entity' => 'C', 'acquired' => '01 Jan 2022', 'cost' => 4_200_000_000, 'life' => '20 tahun', 'method' => 'Garis lurus', 'monthly' => 17_500_000, 'book' => 3_220_000_000],
                ['code' => 'AST-C-0014', 'name' => 'Kendaraan antar-jemput tamu', 'entity' => 'C', 'acquired' => '20 Sep 2025', 'cost' => 385_000_000, 'life' => '5 tahun', 'method' => 'Saldo menurun', 'monthly' => 6_416_667, 'book' => 308_000_000],
                ['code' => 'AST-D-0002', 'name' => 'Rak & display toko', 'entity' => 'D', 'acquired' => '05 Apr 2025', 'cost' => 180_000_000, 'life' => '5 tahun', 'method' => 'Garis lurus', 'monthly' => 3_000_000, 'book' => 129_000_000],
            ],
            'leases' => [
                ['code' => 'SWA-A-0001', 'name' => 'Sewa ruko Kemang Raya', 'entity' => 'A', 'period' => '01 Jan 2025 - 31 Des 2027', 'value' => 1_020_000_000, 'term' => 'Tahunan di muka', 'monthly' => 28_333_333, 'next' => '01 Jan 2027', 'kind' => 'Sewa tempat'],
                ['code' => 'SWA-B-0002', 'name' => 'Sewa lantai 1 Dago Plaza', 'entity' => 'B', 'period' => '01 Okt 2024 - 30 Sep 2027', 'value' => 720_000_000, 'term' => 'Kuartalan', 'monthly' => 20_000_000, 'next' => '01 Okt 2026', 'kind' => 'Sewa tempat'],
                ['code' => 'HTA-C-0001', 'name' => 'Pembiayaan kendaraan antar-jemput', 'entity' => 'C', 'period' => '20 Sep 2025 - 20 Sep 2029', 'value' => 385_000_000, 'term' => '48 angsuran', 'monthly' => 9_420_000, 'next' => '20 Okt 2026', 'kind' => 'Hutang aset'],
            ],
        ];
    }

    /**
     * Jadwal angsuran pembiayaan aset (contoh 6 bulan pertama sisa).

     *

     * @return list<array<string, mixed>>
     */
    public static function instalments(): array
    {
        $rows = [];
        $saldo = 248_600_000;
        $bunga = 0.0075;
        for ($i = 1; $i <= 6; $i++) {
            $b = (int) round($saldo * $bunga);
            $pokok = 9_420_000 - $b;
            $saldo -= $pokok;
            $rows[] = [
                'no' => $i,
                'date' => ['20 Okt 2026', '20 Nov 2026', '20 Des 2026', '20 Jan 2027', '20 Feb 2027', '20 Mar 2027'][$i - 1],
                'instalment' => 9_420_000,
                'interest' => $b,
                'principal' => $pokok,
                'balance' => $saldo,
            ];
        }

        return $rows;
    }

    /**
     * Status kelengkapan entry harian per entitas.

     *

     * @return list<array<string, mixed>>
     */
    public static function readiness(): array
    {
        return [
            ['entity' => 'A', 'sales' => 'ok', 'docs' => 'ok', 'bank' => 'ok', 'last' => '30 Sep 2026 08.10', 'pending' => 1],
            ['entity' => 'B', 'sales' => 'ok', 'docs' => 'warn', 'bank' => 'ok', 'last' => '29 Sep 2026 17.45', 'pending' => 4],
            ['entity' => 'C', 'sales' => 'ok', 'docs' => 'ok', 'bank' => 'warn', 'last' => '30 Sep 2026 07.55', 'pending' => 2],
            ['entity' => 'D', 'sales' => 'warn', 'docs' => 'ok', 'bank' => 'ok', 'last' => '28 Sep 2026 19.20', 'pending' => 3],
        ];
    }

    // ---- Turunan ------------------------------------------------------

    /** @return array{rows: list<array<string, mixed>>, labels: array<string, string>} */
    public static function profitLossTable(): array
    {
        $pl = self::profitLoss();
        $elim = ['sales' => -75_000_000, 'other' => -75_000_000];
        $labels = [
            'sales' => 'Pendapatan', 'cogs' => 'Harga pokok penjualan', 'gross' => 'LABA KOTOR',
            'payroll' => 'Gaji & tunjangan', 'rent' => 'Sewa tempat', 'utility' => 'Listrik, air & gas',
            'other' => 'Beban lain-lain', 'depreciation' => 'Penyusutan', 'expense' => 'Jumlah beban operasional',
            'net' => 'LABA BERSIH',
        ];
        $rows = [];
        foreach (['sales', 'cogs', 'gross', 'payroll', 'rent', 'utility', 'other', 'depreciation', 'expense', 'net'] as $key) {
            $row = ['key' => $key, 'label' => $labels[$key], 'values' => [], 'elim' => $elim[$key] ?? 0];
            foreach (array_keys(self::entities()) as $e) {
                $row['values'][$e] = match ($key) {
                    'gross' => $pl[$e]['sales'] - $pl[$e]['cogs'],
                    'expense' => $pl[$e]['payroll'] + $pl[$e]['rent'] + $pl[$e]['utility'] + $pl[$e]['other'] + $pl[$e]['depreciation'],
                    'net' => $pl[$e]['sales'] - $pl[$e]['cogs'] - ($pl[$e]['payroll'] + $pl[$e]['rent'] + $pl[$e]['utility'] + $pl[$e]['other'] + $pl[$e]['depreciation']),
                    default => $pl[$e][$key],
                };
            }
            if ($key === 'gross') {
                $row['elim'] = -75_000_000;
            }
            if ($key === 'expense') {
                $row['elim'] = -75_000_000;
            }
            $row['sum'] = array_sum($row['values']);
            $row['consolidated'] = $row['sum'] + $row['elim'];
            $rows[] = $row;
        }

        return ['rows' => $rows, 'labels' => $labels];
    }

    /** @return list<array<string, mixed>> */
    public static function balanceTable(): array
    {
        $bs = self::balance();
        $pl = self::profitLossTable();
        $net = [];
        foreach ($pl['rows'] as $r) {
            if ($r['key'] === 'net') {
                $net = $r['values'];
            }
        }
        $elim = ['ar_group' => -150_000_000, 'ap_group' => -150_000_000];
        $spec = [
            ['cash', 'Kas & bank', 'aset'],
            ['ar', 'Piutang usaha', 'aset'],
            ['ar_group', 'Piutang afiliasi', 'aset'],
            ['stock', 'Persediaan', 'aset'],
            ['fixed', 'Aset tetap (neto)', 'aset'],
            ['total_asset', 'JUMLAH ASET', 'total'],
            ['ap', 'Hutang usaha', 'liab'],
            ['ap_group', 'Hutang afiliasi', 'liab'],
            ['capital', 'Modal disetor', 'eq'],
            ['retained', 'Laba ditahan', 'eq'],
            ['profit', 'Laba periode berjalan', 'eq'],
            ['total_liab', 'JUMLAH LIABILITAS & EKUITAS', 'total'],
        ];
        $rows = [];
        foreach ($spec as [$key, $label, $group]) {
            $row = ['key' => $key, 'label' => $label, 'group' => $group, 'values' => [], 'elim' => $elim[$key] ?? 0];
            foreach (array_keys(self::entities()) as $e) {
                $row['values'][$e] = match ($key) {
                    'profit' => $net[$e],
                    'total_asset' => $bs[$e]['cash'] + $bs[$e]['ar'] + $bs[$e]['ar_group'] + $bs[$e]['stock'] + $bs[$e]['fixed'],
                    'total_liab' => $bs[$e]['ap'] + $bs[$e]['ap_group'] + $bs[$e]['capital'] + $bs[$e]['retained'] + $net[$e],
                    default => $bs[$e][$key],
                };
            }
            if ($key === 'total_asset' || $key === 'total_liab') {
                $row['elim'] = -150_000_000;
            }
            $row['sum'] = array_sum($row['values']);
            $row['consolidated'] = $row['sum'] + $row['elim'];
            $rows[] = $row;
        }

        return $rows;
    }

    public static function rp(int|float|string $v, bool $short = false): string
    {
        $v = (int) round((float) $v);
        if ($v === 0) {
            return '-';
        }
        $neg = $v < 0;
        $abs = abs($v);
        $text = $short
            ? number_format($abs / 1_000_000, $abs >= 1_000_000_000 ? 1 : 0, ',', '.').($abs >= 1_000_000 ? ' jt' : '')
            : number_format($abs, 0, ',', '.');

        return ($neg ? '(' : '').$text.($neg ? ')' : '');
    }
}
