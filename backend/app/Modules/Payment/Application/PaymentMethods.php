<?php

namespace App\Modules\Payment\Application;

use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;

/** Daftar metode pembayaran per outlet (FR-PAY-01, FR-PAY-02, FR-PAY-10). */
class PaymentMethods
{
    /** Metode yang didukung Fase 1. `member_balance`, `city_ledger`, dan voucher menyusul (Fase 2). */
    public const DEFAULTS = [
        'cash' => ['label' => 'Tunai', 'active' => true],
        'qris' => ['label' => 'QRIS', 'active' => true],
        'debit' => ['label' => 'Kartu Debit', 'active' => true],
        'credit' => ['label' => 'Kartu Kredit', 'active' => true],
        'ewallet' => ['label' => 'E-Wallet', 'active' => false],
        'transfer' => ['label' => 'Transfer Bank', 'active' => false],
    ];

    /** Metode yang wajib melalui payment gateway (ADR 0004). */
    public const GATEWAY_METHODS = ['qris', 'ewallet'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Buat baris bawaan yang belum ada (outlet baru atau metode baru). */
    public function ensure(Outlet $outlet): void
    {
        $existing = OutletPaymentMethod::query()->where('outlet_id', $outlet->id)->pluck('method')->all();
        $order = 0;
        foreach (self::DEFAULTS as $method => $def) {
            $order++;
            if (in_array($method, $existing, true)) {
                continue;
            }
            $row = new OutletPaymentMethod([
                'outlet_id' => $outlet->id,
                'method' => $method,
                'label' => $def['label'],
                'is_active' => $def['active'],
                'sort_order' => $order,
                'mdr_percent' => '0',
                'mdr_fixed' => '0',
            ]);
            $row->company_id = $outlet->company_id;
            $row->save();
        }
    }

    /** @return Collection<int, OutletPaymentMethod> */
    public function forOutlet(Outlet $outlet): Collection
    {
        $rows = OutletPaymentMethod::query()->where('outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('method')->get();
        if ($rows->count() < count(self::DEFAULTS)) {
            $this->ensure($outlet);
            $rows = OutletPaymentMethod::query()->where('outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('method')->get();
        }

        return $rows;
    }

    /** Biaya MDR = persen × nominal + biaya tetap, dibulatkan ke sen (FR-PAY-10). */
    public static function mdr(?OutletPaymentMethod $config, string $amount): string
    {
        if ($config === null) {
            return '0.00';
        }

        return (string) BigDecimal::of($amount)
            ->multipliedBy((string) $config->mdr_percent)
            ->dividedBy(100, 2, RoundingMode::HALF_UP)
            ->plus((string) $config->mdr_fixed)
            ->toScale(2);
    }
}
