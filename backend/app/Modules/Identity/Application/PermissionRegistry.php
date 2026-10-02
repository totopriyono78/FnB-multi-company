<?php

namespace App\Modules\Identity\Application;

/**
 * Daftar permission dan role bawaan sesuai matriks SRS Lampiran 12.1.
 */
final class PermissionRegistry
{
    /** @var array<string, array<string, string>> grup => [permission => label] */
    public const PERMISSIONS = [
        'company' => [
            'company.manage' => 'Kelola profil company',
            'company.view' => 'Lihat profil company',
            'subscription.manage' => 'Kelola langganan',
            'subscription.view' => 'Lihat langganan',
        ],
        'organization' => [
            'brand.manage' => 'Kelola brand',
            'brand.view' => 'Lihat brand',
            'outlet.manage' => 'Kelola outlet',
            'outlet.view' => 'Lihat outlet',
            'device.manage' => 'Kelola perangkat',
            'device.view' => 'Lihat perangkat',
        ],
        'users' => [
            'user.manage' => 'Kelola user seluruh company',
            'user.manage_outlet' => 'Kelola user di outlet sendiri',
            'role.manage' => 'Kelola role',
        ],
        'menu' => [
            'menu.manage' => 'Kelola menu & harga',
            'menu.view' => 'Lihat menu & harga',
            'menu.sold_out' => 'Tandai menu habis',
        ],
        'pos' => [
            'pos.transact' => 'Transaksi POS',
            'pos.shift' => 'Buka/tutup shift',
            'pos.discount' => 'Beri diskon manual',
            'pos.void' => 'Void / refund',
            'pos.open_drawer' => 'Buka laci tanpa transaksi',
            'pos.price_override' => 'Ubah harga',
            'pos.end_of_day' => 'Tutup hari',
        ],
        'kitchen' => [
            'kds.use' => 'Pakai KDS',
        ],
        'inventory' => [
            'inventory.manage' => 'Kelola inventory & opname',
            'inventory.approve_count' => 'Setujui hasil stock opname',
            'inventory.view' => 'Lihat inventory',
            'purchasing.manage' => 'Kelola purchasing',
            'purchasing.approve' => 'Setujui purchase order',
            'purchasing.request' => 'Ajukan permintaan pembelian',
            'purchasing.view' => 'Lihat purchasing',
        ],
        'reports' => [
            'report.sales.company' => 'Laporan penjualan seluruh company',
            'report.sales.brand' => 'Laporan penjualan per brand',
            'report.sales.outlet' => 'Laporan penjualan per outlet',
            'report.sales.own_shift' => 'Laporan shift sendiri',
        ],
        'finance' => [
            'accounting.manage' => 'Kelola akuntansi',
            'accounting.view' => 'Lihat akuntansi',
        ],
        /*
         * Dokumen pembayaran (Kelompok 4). Kewenangan MENYETUJUI sengaja TIDAK berupa izin:
         * ia ditentukan matriks batas wewenang (DOC-09) yang menyebut peran per tingkat per nilai.
         * Dua sistem kewenangan yang saling tumpang tindih hanya akan saling membatalkan — yang
         * satu mengizinkan, yang lain menolak, dan tidak ada yang tahu mana yang berlaku.
         */
        'document' => [
            'payment.request' => 'Ajukan pembayaran (SPPK)',
            'payment.pay' => 'Terbitkan advis bayar',
            'payment.view' => 'Lihat dokumen pembayaran',
        ],
        /*
         * Kas, bank, hutang & piutang (Kelompok 5). Rekonsiliasi dipisahkan dari pencatatan dengan
         * sengaja: mencatat uang keluar dan menyatakan "catatan ini sudah cocok dengan rekening
         * koran" adalah dua pekerjaan yang saling memeriksa. Entitas yang orangnya cukup bisa
         * memisahkannya; yang tidak cukup, cukup memberikan keduanya ke orang yang sama — tetapi
         * pilihannya ada, dan itu gunanya dipisah.
         */
        'treasury' => [
            'treasury.manage' => 'Catat mutasi kas, faktur pembelian & tagihan',
            'treasury.reconcile' => 'Rekonsiliasi bank',
            'treasury.view' => 'Lihat kas, bank, hutang & piutang',
        ],
        /*
         * Holding & konsolidasi (Kelompok 8). Dua izin ini sengaja TIDAK memberi akses apa pun ke
         * transaksi anak usaha — dan itu bukan janji di kode, melainkan akibat bentuk datanya: hasil
         * konsolidasi adalah data milik entitas holding, sehingga RLS PostgreSQL yang menolak
         * pembacaan buku entitas lain, bukan pemeriksaan izin yang bisa terlupa di satu layar.
         */
        'consolidation' => [
            'consolidation.manage' => 'Kelola grup, tarik saldo & entri eliminasi',
            'consolidation.view' => 'Lihat kertas kerja & laporan konsolidasi',
        ],
        'audit' => [
            'audit.view' => 'Lihat audit log',
        ],
    ];

    /**
     * Izin yang memberi kewenangan mengelola/menyetujui. Hanya boleh diberikan oleh user
     * yang memilikinya sendiri (atau pemilik). Izin operasional lain (transaksi, lihat data,
     * KDS) boleh diberikan oleh siapa pun yang berwenang mengelola staf/role.
     */
    public const PRIVILEGED = [
        'company.manage', 'subscription.manage', 'brand.manage', 'outlet.manage', 'device.manage',
        'user.manage', 'user.manage_outlet', 'role.manage', 'menu.manage', 'inventory.manage',
        'inventory.approve_count', 'purchasing.manage', 'purchasing.approve', 'accounting.manage', 'accounting.view', 'audit.view',
        'payment.pay', 'payment.view',
        'treasury.manage', 'treasury.reconcile', 'treasury.view',
        'consolidation.manage', 'consolidation.view',
        'pos.void', 'pos.discount', 'pos.open_drawer', 'pos.price_override', 'pos.end_of_day',
        'report.sales.company', 'report.sales.brand', 'report.sales.outlet',
    ];

    /** Izin yang otomatis tercakup oleh izin yang lebih luas. */
    public const IMPLIES = [
        'user.manage' => ['user.manage_outlet'],
        'report.sales.company' => ['report.sales.brand', 'report.sales.outlet'],
        'report.sales.brand' => ['report.sales.outlet'],
        'accounting.manage' => ['accounting.view'],
        'payment.request' => ['payment.view'],
        'payment.pay' => ['payment.view'],
        'treasury.manage' => ['treasury.view'],
        'treasury.reconcile' => ['treasury.view'],
        'consolidation.manage' => ['consolidation.view'],
        'inventory.manage' => ['inventory.view'],
        'purchasing.manage' => ['purchasing.view', 'purchasing.request'],
    ];

    /**
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    public static function expand(iterable $permissions): array
    {
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = true;
            foreach (self::IMPLIES[$permission] ?? [] as $implied) {
                $result[$implied] = true;
            }
        }

        return array_keys($result);
    }

    /** Aksi POS yang wajib otorisasi supervisor (FR-AUTH-07) => permission yang dibutuhkan pemberi otorisasi. */
    public const SUPERVISOR_ACTIONS = [
        'void' => 'pos.void',
        'refund' => 'pos.void',
        /*
         * Memeriksa ulang tagihan yang terlanjur gagal ke gateway. Yang memutuskan tetap jawaban
         * gateway — kasir tidak pernah bisa menandai lunas sendiri — tetapi pemulihannya menyentuh
         * uang, jadi kewenangannya disamakan dengan void: siapa yang boleh membatalkan transaksi,
         * boleh pula menyetujui pemeriksaan ulang ini.
         */
        'payment_recheck' => 'pos.void',
        'discount' => 'pos.discount',
        'open_drawer' => 'pos.open_drawer',
        'price_override' => 'pos.price_override',
    ];

    /**
     * @return array<string, array{label: string, max_discount: string, permissions: list<string>}>
     */
    public static function defaultRoles(): array
    {
        $all = self::all();

        return [
            'owner' => [
                'label' => 'Pemilik',
                'max_discount' => '100',
                /*
                 * Pemilik melihat semuanya dan bisa menyetujui lewat matriks, tetapi tidak
                 * mengajukan dan tidak menerbitkan advis bayar sendiri — sama alasannya dengan
                 * accounting.manage: yang menyatakan angkanya benar sebaiknya bukan yang
                 * menyetujuinya.
                 */
                'permissions' => array_values(array_diff($all, [
                    'pos.transact', 'pos.shift', 'kds.use', 'accounting.manage', 'user.manage_outlet',
                    'report.sales.own_shift', 'purchasing.request', 'payment.request', 'payment.pay',
                    'treasury.manage', 'treasury.reconcile', 'consolidation.manage',
                ])),
            ],
            'company_admin' => [
                'label' => 'Admin Company',
                'max_discount' => '50',
                'permissions' => [
                    'company.manage', 'company.view', 'subscription.manage', 'subscription.view',
                    'brand.manage', 'brand.view', 'outlet.manage', 'outlet.view', 'device.manage', 'device.view',
                    'user.manage', 'role.manage', 'menu.manage', 'menu.view', 'menu.sold_out',
                    'pos.discount', 'pos.void', 'pos.open_drawer', 'pos.price_override', 'pos.end_of_day',
                    'inventory.manage', 'inventory.approve_count', 'inventory.view',
                    'purchasing.manage', 'purchasing.approve', 'purchasing.view',
                    'report.sales.company', 'audit.view', 'payment.view', 'treasury.view',
                    'consolidation.view',
                ],
            ],
            'brand_manager' => [
                'label' => 'Manajer Brand',
                'max_discount' => '0',
                'permissions' => [
                    'brand.view', 'outlet.view', 'menu.manage', 'menu.view', 'menu.sold_out',
                    'inventory.view', 'report.sales.brand',
                ],
            ],
            'outlet_manager' => [
                'label' => 'Manajer Outlet',
                'max_discount' => '50',
                'permissions' => [
                    'brand.view', 'outlet.view', 'device.view', 'user.manage_outlet', 'menu.view', 'menu.sold_out',
                    'pos.transact', 'pos.shift', 'pos.discount', 'pos.void', 'pos.open_drawer',
                    'pos.price_override', 'pos.end_of_day', 'kds.use', 'inventory.manage', 'inventory.approve_count', 'inventory.view',
                    'purchasing.request', 'report.sales.outlet', 'report.sales.own_shift', 'audit.view',
                    // Daftar peran di berkas ini ditulis UTUH, tidak mengandalkan IMPLIES: IMPLIES
                    // hanya dipakai GrantGuard untuk menilai siapa boleh memberikan izin apa.
                    'payment.request', 'payment.view',
                ],
            ],
            'cashier' => [
                'label' => 'Kasir',
                'max_discount' => '0',
                'permissions' => ['menu.sold_out', 'pos.transact', 'pos.shift', 'report.sales.own_shift'],
            ],
            'kitchen' => [
                'label' => 'Dapur / Barista',
                'max_discount' => '0',
                'permissions' => ['menu.sold_out', 'kds.use'],
            ],
            'warehouse' => [
                'label' => 'Gudang / Purchasing',
                'max_discount' => '0',
                'permissions' => ['inventory.manage', 'inventory.view', 'purchasing.manage', 'purchasing.view'],
            ],
            'finance' => [
                'label' => 'Finance',
                'max_discount' => '0',
                'permissions' => [
                    'company.view', 'subscription.view', 'inventory.view', 'purchasing.view',
                    'report.sales.company', 'accounting.manage', 'accounting.view', 'audit.view',
                    'payment.request', 'payment.pay', 'payment.view',
                    'treasury.manage', 'treasury.reconcile', 'treasury.view',
                    'consolidation.manage', 'consolidation.view',
                ],
            ],
            /*
             * Konsolidator (GRP-02): peran paling sempit di seluruh sistem, dan sengaja begitu. Ia
             * mengerjakan angka grup dan TIDAK PERNAH melihat transaksi satu entitas pun — bukan
             * karena layarnya disembunyikan, tetapi karena izin yang ia punya tidak menyentuh satu
             * tabel transaksi pun.
             */
            'consolidator' => [
                'label' => 'Konsolidator',
                'max_discount' => '0',
                'permissions' => ['company.view', 'consolidation.manage', 'consolidation.view'],
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(self::PERMISSIONS)));
    }

    public static function groupOf(string $permission): ?string
    {
        foreach (self::PERMISSIONS as $group => $items) {
            if (array_key_exists($permission, $items)) {
                return $group;
            }
        }

        return null;
    }

    public static function label(string $permission): string
    {
        foreach (self::PERMISSIONS as $items) {
            if (isset($items[$permission])) {
                return $items[$permission];
            }
        }

        return $permission;
    }
}
