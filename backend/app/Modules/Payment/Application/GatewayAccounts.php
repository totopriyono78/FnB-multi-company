<?php

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Domain\Models\PaymentGatewayAccount;
use App\Modules\Sales\Application\SalesException;

/**
 * Mencari kredensial merchant yang berlaku untuk sebuah outlet.
 *
 * Urutan: kredensial khusus outlet dulu, baru kredensial tingkat company (outlet_id NULL).
 * Tidak ada jalan ketiga — kalau keduanya kosong, transaksi ditolak dengan pesan yang bisa
 * ditindaklanjuti kasir, bukan dibiarkan jatuh ke kredensial bawaan milik siapa pun.
 */
class GatewayAccounts
{
    /** @var array<string, PaymentGatewayAccount|null> Cache per request; satu kasir bisa memanggil beberapa kali. */
    private array $cache = [];

    public function find(string $provider, string $outletId): ?PaymentGatewayAccount
    {
        $key = $provider.'|'.$outletId;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        /** @var PaymentGatewayAccount|null $account */
        $account = PaymentGatewayAccount::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('outlet_id', $outletId)->orWhereNull('outlet_id'))
            // Baris milik outlet menang atas baris tingkat company.
            ->orderByRaw('outlet_id IS NULL')
            ->first();

        return $this->cache[$key] = $account;
    }

    /** @throws SalesException bila outlet belum punya kredensial yang aktif. */
    public function require(string $provider, string $outletId): PaymentGatewayAccount
    {
        return $this->find($provider, $outletId) ?? throw new SalesException(
            'GATEWAY_NOT_CONFIGURED',
            'Outlet ini belum punya kredensial payment gateway. Hubungi admin untuk mengisinya.',
            422,
        );
    }

    /** Dipakai uji dan perintah konsol yang mengganti data di tengah proses. */
    public function forget(): void
    {
        $this->cache = [];
    }
}
