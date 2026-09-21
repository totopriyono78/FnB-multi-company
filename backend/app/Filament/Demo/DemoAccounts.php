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
            ['email' => 'rina@kopinusantara.test', 'name' => 'Rina Hartono', 'role' => 'Pemilik', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => '802614'],
            ['email' => 'bayu@kopinusantara.test', 'name' => 'Bayu Pratama', 'role' => 'Admin Company', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => null],
            ['email' => 'dewi@kopinusantara.test', 'name' => 'Dewi Lestari', 'role' => 'Manajer Outlet Kemang', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => '482915'],
            ['email' => 'rudi@kopinusantara.test', 'name' => 'Rudi Hartanto', 'role' => 'Gudang / Purchasing', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => null],
            ['email' => 'lina@kopinusantara.test', 'name' => 'Lina Kusuma', 'role' => 'Finance', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => null],
            ['email' => 'andi@kopinusantara.test', 'name' => 'Andi Saputra', 'role' => 'Kasir Kemang', 'company' => 'PT Kopi Nusantara Sejahtera', 'pin' => '7351'],
            ['email' => 'ratna@dapurburatna.test', 'name' => 'Ratna Wulandari', 'role' => 'Pemilik', 'company' => 'CV Dapur Bu Ratna', 'pin' => null],
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
