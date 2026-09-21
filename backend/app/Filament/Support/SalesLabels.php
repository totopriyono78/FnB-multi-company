<?php

namespace App\Filament\Support;

use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Tenancy\Domain\Models\Outlet;

/** Label Bahasa Indonesia untuk data transaksi di back-office. */
final class SalesLabels
{
    public const ORDER_STATUS = [
        'paid' => 'Lunas',
        'voided' => 'Dibatalkan',
        'refunded' => 'Refund penuh',
        'partially_refunded' => 'Refund sebagian',
    ];

    public const FLAGS = [
        'price_mismatch' => 'Harga beda dengan katalog',
        'price_override' => 'Harga diubah manual',
        'promo_mismatch' => 'Promo tidak sesuai aturan',
        'promo_quota_exceeded' => 'Kuota promo terlampaui',
        'config_mismatch' => 'Pengaturan pajak berbeda',
        'offline_authorization' => 'Otorisasi saat offline',
        'payment_method_inactive' => 'Metode bayar nonaktif',
        'gateway_refund_required' => 'Perlu refund di gateway',
        'closed_day_refund' => 'Refund hari yang sudah ditutup',
        'refund_method_changed' => 'Refund tunai untuk bayar non-tunai',
    ];

    public const CASH_TYPES = ['in' => 'Kas masuk', 'out' => 'Kas keluar', 'drawer_open' => 'Buka laci'];

    public const STOCK_ACTIONS = ['return' => 'Kembali ke stok', 'waste' => 'Dibuang (waste)'];

    public static function status(?string $status): string
    {
        return self::ORDER_STATUS[$status] ?? (string) $status;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'voided' => 'danger',
            default => 'warning',
        };
    }

    public static function flag(string $flag): string
    {
        return self::FLAGS[$flag] ?? $flag;
    }

    public static function method(?string $method): string
    {
        return PaymentMethods::DEFAULTS[$method]['label'] ?? (string) $method;
    }

    public static function channel(?string $code): string
    {
        // Sekali per request; bergantung company aktif sehingga tidak disimpan statis.
        $names = once(fn () => SalesChannel::query()->pluck('name', 'code')->all());

        return $names[$code] ?? (string) $code;
    }

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canView(): bool
    {
        $user = self::user();

        return $user !== null && app(SalesAccess::class)->canView($user);
    }

    /** @return list<string> */
    public static function outletIds(): array
    {
        $user = self::user();

        return $user === null || ! self::canView() ? [] : app(SalesAccess::class)->outletIds($user);
    }

    /** @return array<string, string> */
    public static function outletOptions(): array
    {
        return Outlet::withTrashed()->whereIn('id', self::outletIds())->orderBy('name')->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Outlet $o) => [$o->id => "{$o->name} ({$o->code})"])->all();
    }
}
