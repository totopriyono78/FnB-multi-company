<?php

namespace App\Prototype;

/**
 * Katalog contoh untuk PROTOTIPE tampilan POS (tidak menyentuh basis data).
 */
class DemoPos
{
    /** Folder foto produk, relatif terhadap public/. */
    public const IMAGE_DIR = 'img/pos';

    /** Ekstensi yang dicari, berurutan. */
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Menu beserta URL foto produk bila berkasnya ada di public/img/pos.
     *
     * @return list<array<string, mixed>>
     */
    public static function menuWithImages(): array
    {
        $groups = self::menu();
        foreach ($groups as $gi => $group) {
            foreach ($group['items'] as $ii => $item) {
                $slug = self::slug($item['name']);
                $groups[$gi]['items'][$ii]['slug'] = $slug;
                $groups[$gi]['items'][$ii]['image'] = self::imageUrl($slug);
                $groups[$gi]['items'][$ii]['initials'] = self::initials($item['name']);
            }
        }

        return $groups;
    }

    public static function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = str_replace(['&', '+'], ' dan ', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', $name) ?: [];
        $letters = '';
        foreach ($words as $w) {
            $c = mb_substr($w, 0, 1);
            if (preg_match('/[A-Za-z]/', $c)) {
                $letters .= mb_strtoupper($c);
            }
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : '#';
    }

    private static function imageUrl(string $slug): ?string
    {
        foreach (self::EXTENSIONS as $ext) {
            $rel = self::IMAGE_DIR.'/'.$slug.'.'.$ext;
            if (is_file(public_path($rel))) {
                return asset($rel).'?v='.@filemtime(public_path($rel));
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function menu(): array
    {
        return [
            [
                'name' => 'Makanan', 'icon' => '🍛', 'tint' => '#fef3c7',
                'items' => [
                    ['name' => 'Nasi Goreng Kampung', 'price' => 44000, 'emoji' => '🍚', 'mod' => 'Level pedas: Sedang'],
                    ['name' => 'Ayam Bakar Taliwang', 'price' => 63000, 'emoji' => '🍗'],
                    ['name' => 'Iga Bakar Madu', 'price' => 94000, 'emoji' => '🥩', 'note' => 'Stok menipis'],
                    ['name' => 'Sop Buntut', 'price' => 89000, 'emoji' => '🍲', 'out' => true],
                    ['name' => 'Gurame Asam Manis', 'price' => 118000, 'emoji' => '🐟'],
                    ['name' => 'Mie Goreng Seafood', 'price' => 52000, 'emoji' => '🍜'],
                    ['name' => 'Cap Cay Kuah', 'price' => 46000, 'emoji' => '🥬'],
                    ['name' => 'Sate Ayam (10 tusuk)', 'price' => 55000, 'emoji' => '🍢'],
                    ['name' => 'Nasi Putih', 'price' => 8000, 'emoji' => '🍚'],
                ],
            ],
            [
                'name' => 'Minuman', 'icon' => '🥤', 'tint' => '#dbeafe',
                'items' => [
                    ['name' => 'Es Kopi Susu Gula Aren', 'price' => 23000, 'emoji' => '🧋', 'mod' => 'Gula: Normal · Es: Normal'],
                    ['name' => 'Americano', 'price' => 22000, 'emoji' => '☕'],
                    ['name' => 'Teh Tarik', 'price' => 19000, 'emoji' => '🍵'],
                    ['name' => 'Jus Alpukat', 'price' => 28000, 'emoji' => '🥑'],
                    ['name' => 'Es Jeruk Peras', 'price' => 18000, 'emoji' => '🍊'],
                    ['name' => 'Air Mineral 600 ml', 'price' => 9000, 'emoji' => '💧'],
                    ['name' => 'Lemon Tea Panas', 'price' => 17000, 'emoji' => '🍋'],
                ],
            ],
            [
                'name' => 'Paket', 'icon' => '🎁', 'tint' => '#e0e7ff',
                'items' => [
                    ['name' => 'Paket Hemat Nasi + Ayam + Teh', 'price' => 68000, 'emoji' => '🍱', 'note' => 'Paket'],
                    ['name' => 'Paket Keluarga (4 orang)', 'price' => 245000, 'emoji' => '👨‍👩‍👧‍👦', 'note' => 'Paket'],
                    ['name' => 'Paket Berdua Iga + 2 Minum', 'price' => 178000, 'emoji' => '🍽️', 'note' => 'Paket'],
                    ['name' => 'Paket Sarapan 07-10', 'price' => 39000, 'emoji' => '🌅', 'note' => 'Promo jam tertentu'],
                ],
            ],
            [
                'name' => 'Dessert', 'icon' => '🍰', 'tint' => '#fce7f3',
                'items' => [
                    ['name' => 'Pisang Goreng Keju', 'price' => 32000, 'emoji' => '🍌'],
                    ['name' => 'Es Campur', 'price' => 29000, 'emoji' => '🍧'],
                    ['name' => 'Puding Cokelat', 'price' => 24000, 'emoji' => '🍮'],
                    ['name' => 'Klapertaart', 'price' => 38000, 'emoji' => '🥥'],
                ],
            ],
            [
                'name' => 'Tambahan', 'icon' => '➕', 'tint' => '#dcfce7',
                'items' => [
                    ['name' => 'Sambal Matah', 'price' => 12000, 'emoji' => '🌶️'],
                    ['name' => 'Kerupuk Udang', 'price' => 10000, 'emoji' => '🍘'],
                    ['name' => 'Telur Ceplok', 'price' => 9000, 'emoji' => '🍳'],
                    ['name' => 'Extra Nasi', 'price' => 8000, 'emoji' => '🍚'],
                    ['name' => 'Kemasan Bawa Pulang', 'price' => 3000, 'emoji' => '🥡'],
                ],
            ],
        ];
    }
}
