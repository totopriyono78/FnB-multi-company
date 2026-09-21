<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Payment\Application\PaymentMethods;
use Illuminate\Support\Facades\DB;

/** Nama tampilan untuk kunci dimensi laporan (satu query per dimensi). */
class ReportLabels
{
    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function names(string $dimension, array $keys): array
    {
        $ids = array_values(array_filter($keys, fn (string $k) => preg_match('/^[0-9a-f-]{36}$/i', $k) === 1));

        $map = match ($dimension) {
            'outlet' => $ids === [] ? [] : DB::table('outlets')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'brand' => $ids === [] ? [] : DB::table('brands')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'category' => $ids === [] ? [] : DB::table('menu_categories')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'item' => $ids === [] ? [] : DB::table('items')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            // Tabel users bersifat global; hanya ID yang tercatat di transaksi company ini yang dibaca.
            'cashier' => $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all(),
            'channel' => $keys === [] ? [] : DB::table('sales_channels')->whereIn('code', $keys)->pluck('name', 'code')->all(),
            default => [],
        };

        /** @var array<string, string> $out */
        $out = [];
        foreach ($map as $k => $v) {
            $out[(string) $k] = (string) $v;
        }
        if ($dimension === 'channel') {
            foreach ($keys as $k) {
                $out[$k] ??= self::channelFallback($k);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $outletIds
     * @return array<string, string> outlet_id => brand_id
     */
    public function outletBrands(array $outletIds): array
    {
        if ($outletIds === []) {
            return [];
        }

        /** @var array<string, string> $map */
        $map = DB::table('outlets')->whereIn('id', $outletIds)->pluck('brand_id', 'id')->all();

        return $map;
    }

    public static function paymentMethod(string $method): string
    {
        return PaymentMethods::DEFAULTS[$method]['label'] ?? ucfirst(str_replace('_', ' ', $method));
    }

    private static function channelFallback(string $code): string
    {
        return match ($code) {
            'dine_in' => 'Makan di tempat',
            'take_away' => 'Bawa pulang',
            default => ucfirst(str_replace('_', ' ', $code)),
        };
    }
}
