<?php

namespace App\Filament\Demo;

/**
 * Akun demo yang dibuat oleh DemoSeeder. Hanya ditampilkan di halaman login
 * bila FNB_DEMO_LOGIN=true dan aplikasi tidak berjalan di produksi.
 */
final class DemoAccounts
{
    public const PASSWORD = 'Rahasia123';

    /**
     * @return list<array{email: string, name: string, role: string, company: string, pin: string|null}>
     */
    public static function all(): array
    {
        return [
            ['email' => 'rina@gtgroup.test', 'name' => 'Rina Hartono', 'role' => 'Pemilik', 'company' => 'Gamatechno Group', 'pin' => '802614'],
            ['email' => 'bayu@gtgroup.test', 'name' => 'Bayu Pratama', 'role' => 'Admin Company', 'company' => 'Gamatechno Group', 'pin' => null],
            ['email' => 'lina@gtgroup.test', 'name' => 'Lina Kusuma', 'role' => 'Finance', 'company' => 'Gamatechno Group', 'pin' => null],
            ['email' => 'rudi@gtgroup.test', 'name' => 'Rudi Hartanto', 'role' => 'Gudang / Purchasing', 'company' => 'Gamatechno Group', 'pin' => null],
            ['email' => 'dewi@gtgroup.test', 'name' => 'Dewi Lestari', 'role' => 'Manajer Hamzah Coffee Kaliurang', 'company' => 'Gamatechno Group', 'pin' => '482915'],
            ['email' => 'andi@gtgroup.test', 'name' => 'Andi Saputra', 'role' => 'Kasir Hamzah Coffee Kaliurang', 'company' => 'Gamatechno Group', 'pin' => '7351'],
            ['email' => 'yohanes@gtgroup.test', 'name' => 'Yohanes Siregar', 'role' => 'Manajer Hamzah Coffee Prawirotaman', 'company' => 'Gamatechno Group', 'pin' => '615283'],
            ['email' => 'aminah@gtgroup.test', 'name' => 'Siti Aminah', 'role' => 'Manajer Hamzah Resto Ikan Bakar', 'company' => 'Gamatechno Group', 'pin' => '260418'],
            ['email' => 'yusuf@gtgroup.test', 'name' => 'Yusuf Maulana', 'role' => 'Kasir Hamzah Resto Ikan Bakar', 'company' => 'Gamatechno Group', 'pin' => '4719'],
            ['email' => 'rizky@gtgroup.test', 'name' => 'Rizky Ramadhan', 'role' => 'Manajer Hamzah Resto Jl. Magelang', 'company' => 'Gamatechno Group', 'pin' => '5836'],
            ['email' => 'hendra@gtgroup.test', 'name' => 'Hendra Gunawan', 'role' => 'Kasir Hamzah Resto Jl. Magelang', 'company' => 'Gamatechno Group', 'pin' => '5172'],
        ];
    }

    /**
     * PIN staf POS pada data demo (DemoSeeder), untuk mempermudah peragaan.
     * Hanya dipakai bila DemoAccounts::enabled() bernilai true.
     *
     * @return array<string, string> nama staf => PIN
     */
    public static function posPins(): array
    {
        return [
            'Rina Hartono' => '802614',
            'Dewi Lestari' => '482915',
            'Andi Saputra' => '7351',
            'Siti Nurhaliza' => '9024',
            'Yohanes Siregar' => '615283',
            'Putri Maharani' => '3867',
            'Siti Aminah' => '260418',
            'Yusuf Maulana' => '4719',
            'Rizky Ramadhan' => '5836',
            'Hendra Gunawan' => '5172',
        ];
    }

    public static function enabled(): bool
    {
        return (bool) config('fnb.demo_login') && ! app()->isProduction();
    }

    public static function has(string $email): bool
    {
        return in_array($email, array_column(self::all(), 'email'), true);
    }
}
