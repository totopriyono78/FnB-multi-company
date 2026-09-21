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
        'pos.void', 'pos.discount', 'pos.open_drawer', 'pos.price_override', 'pos.end_of_day',
        'report.sales.company', 'report.sales.brand', 'report.sales.outlet',
    ];

    /** Izin yang otomatis tercakup oleh izin yang lebih luas. */
    public const IMPLIES = [
        'user.manage' => ['user.manage_outlet'],
        'report.sales.company' => ['report.sales.brand', 'report.sales.outlet'],
        'report.sales.brand' => ['report.sales.outlet'],
        'accounting.manage' => ['accounting.view'],
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
                'permissions' => array_values(array_diff($all, [
                    'pos.transact', 'pos.shift', 'kds.use', 'accounting.manage', 'user.manage_outlet',
                    'report.sales.own_shift', 'purchasing.request',
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
                    'report.sales.company', 'audit.view',
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
                ],
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
