<?php

namespace App\Modules\Catalog\Application;

/** Aturan bisnis promo yang melibatkan beberapa field sekaligus. Dipakai API dan back-office. */
final class PromotionRules
{
    /**
     * @param  array<string, mixed>  $p  atribut promo setelah perubahan (type, scope, value, buy_qty, get_qty, auto_apply, code)
     * @return array<string, string> field => pesan
     */
    public static function errors(array $p, int $targetCount): array
    {
        $errors = [];
        $type = $p['type'] ?? null;
        $scope = $p['scope'] ?? 'order';
        $value = is_numeric($p['value'] ?? null) ? (float) $p['value'] : 0.0;

        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            $errors['value'] = 'Diskon persen harus lebih dari 0 dan maksimal 100.';
        }
        if (in_array($type, ['amount', 'special_price'], true) && $value <= 0) {
            $errors['value'] = 'Nilai promo wajib diisi.';
        }
        if ($type === 'buy_x_get_y' && (empty($p['buy_qty']) || empty($p['get_qty']))) {
            $errors['buy_qty'] = 'Isi jumlah beli dan jumlah gratis.';
        }
        if (in_array($type, ['buy_x_get_y', 'special_price'], true) && $scope !== 'items') {
            $errors['scope'] = 'Promo ini berlaku per menu; pilih cakupan "items".';
        }
        if ($scope === 'items' && $targetCount === 0) {
            $errors['item_ids'] = 'Pilih menu atau kategori yang mendapat promo.';
        }
        if (! ($p['auto_apply'] ?? true) && empty($p['code'])) {
            $errors['code'] = 'Promo yang tidak otomatis harus memiliki kode.';
        }

        return $errors;
    }
}
