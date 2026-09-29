<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Catalog\Http\Requests\MenuCategoryRequest;
use App\Modules\Tenancy\Domain\Models\Brand;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Impor/ekspor kategori menu via Excel atau CSV (FR-MENU-09, diminta user 28 Sep 2026).
 *
 * Kategori sebetulnya sudah lahir otomatis dari kolom `kategori` saat mengimpor menu — tetapi
 * hanya namanya; warna tombol di POS, ikon, urutan, dan status aktif memakai nilai bawaan.
 * Kelas ini untuk pekerjaan yang lain: menata puluhan kategori sekaligus, atau mengubah
 * urutannya tanpa mengklik satu per satu.
 *
 * **Kuncinya nama kategori** (tanpa membedakan huruf besar-kecil), sama persis dengan indeks unik
 * `menu_categories_name_unique (company_id, brand_id, lower(name))` di basis data. Jadi impor
 * ulang berkas yang sama memperbarui baris yang sama, bukan menggandakannya.
 *
 * **Yang tidak ada di berkas TIDAK disentuh** — keputusan user 28 Sep 2026. Impor hanya menambah
 * dan memperbarui. Alasannya bukan kehati-hatian umum: kategori diacu menu dan lewat menu diacu
 * riwayat transaksi, jadi berkas yang kebetulan kurang lengkap tidak boleh bisa mengosongkan
 * layar kasir atau memutus kaitan data lama.
 *
 * Seperti impor menu: semua-atau-tidak-sama-sekali, dengan mode "periksa saja".
 */
class MenuCategorySpreadsheet
{
    public const HEADERS = ['nama', 'warna', 'ikon', 'urutan', 'aktif'];

    public const REQUIRED = ['nama'];

    public const MAX_ROWS = 2000;

    /**
     * @return array{created: int, updated: int, errors: list<array{row: int, message: string}>, dry_run: bool}
     */
    public function import(Brand $brand, string $path, string $extension, bool $dryRun = false): array
    {
        $rows = SpreadsheetIo::read($path, $extension, self::REQUIRED, self::MAX_ROWS);
        $errors = [];
        $parsed = [];
        $names = [];

        foreach ($rows as $n => $row) {
            $line = $n + 2; // baris 1 adalah header
            try {
                $category = $this->parseRow($row);
                $key = mb_strtolower($category['nama']);
                if (isset($names[$key])) {
                    throw new \InvalidArgumentException(
                        "Kategori \"{$category['nama']}\" muncul lebih dari sekali (baris {$names[$key]})."
                    );
                }
                $names[$key] = $line;
                $parsed[$line] = $category;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }

        if ($parsed === [] && $errors === []) {
            $errors[] = ['row' => 1, 'message' => 'File tidak berisi data kategori.'];
        }

        $report = ['created' => 0, 'updated' => 0, 'errors' => $errors, 'dry_run' => $dryRun];
        if ($errors !== []) {
            usort($report['errors'], fn ($a, $b) => $a['row'] <=> $b['row']);

            return $report;
        }

        DB::beginTransaction();
        try {
            $existing = MenuCategory::query()
                ->where('brand_id', $brand->id)
                ->get()
                ->keyBy(fn (MenuCategory $c) => mb_strtolower($c->name));

            foreach ($parsed as $category) {
                $current = $existing->get(mb_strtolower($category['nama']));

                /*
                 * Kolom kosong berarti "biarkan seperti sekarang", bukan "kembalikan ke bawaan".
                 * Berkas yang hanya berisi kolom nama dan urutan — cara tercepat menata ulang
                 * susunan tombol di POS — karena itu tidak ikut memutihkan warna dan ikonnya.
                 */
                $ada = $current instanceof MenuCategory;
                $payload = [
                    'brand_id' => $brand->id,
                    'name' => $category['nama'],
                    'color' => $category['warna'] ?? ($ada ? $current->color : 'gray'),
                    'icon' => $category['ikon'] ?? ($ada ? $current->icon : null),
                    'sort_order' => $category['urutan'] ?? ($ada ? $current->sort_order : 0),
                    'is_active' => $category['aktif'] ?? ($ada ? $current->is_active : true),
                ];

                if ($ada) {
                    $current->update($payload);
                    $report['updated']++;
                } else {
                    MenuCategory::query()->create($payload);
                    $report['created']++;
                }
            }

            // Mode pratinjau: hitung hasilnya, lalu batalkan sebelum tersimpan.
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /** Tulis file Excel kategori satu brand; mengembalikan path file sementara. */
    public function export(Brand $brand): string
    {
        $path = SpreadsheetIo::tempXlsx('kategori');
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(SpreadsheetIo::textRow(self::HEADERS));

        MenuCategory::query()
            ->where('brand_id', $brand->id)
            ->orderBy('sort_order')->orderBy('name')
            ->chunk(500, function ($categories) use ($writer): void {
                foreach ($categories as $category) {
                    /** @var MenuCategory $category */
                    $writer->addRow(SpreadsheetIo::textRow([
                        $category->name,
                        $category->color,
                        $category->icon ?? '',
                        (string) $category->sort_order,
                        $category->is_active ? 'ya' : 'tidak',
                    ]));
                }
            });

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{nama: string, warna: string|null, ikon: string|null, urutan: int|null, aktif: bool|null}
     */
    private function parseRow(array $row): array
    {
        $get = fn (string $k): ?string => ($v = trim((string) ($row[$k] ?? ''))) === '' ? null : $v;

        $name = $get('nama');
        if ($name === null) {
            throw new \InvalidArgumentException('Nama kategori wajib diisi.');
        }
        if (mb_strlen($name) > 60) {
            throw new \InvalidArgumentException('Nama kategori maksimal 60 karakter.');
        }

        $color = $get('warna');
        if ($color !== null) {
            $color = mb_strtolower($color);
            if (! in_array($color, MenuCategoryRequest::COLORS, true)) {
                // Daftarnya disebutkan: pengguna menulis "merah" atau "#ff0000" jauh lebih sering
                // daripada menebak dengan benar, dan pesan "warna tidak valid" saja tidak menolong.
                throw new \InvalidArgumentException(
                    "Warna \"{$color}\" tidak dikenal. Pilihan: ".implode(', ', MenuCategoryRequest::COLORS).'.'
                );
            }
        }

        $icon = $get('ikon');
        if ($icon !== null && mb_strlen($icon) > 40) {
            throw new \InvalidArgumentException('Kolom ikon maksimal 40 karakter.');
        }

        $order = $get('urutan');
        if ($order !== null) {
            if (! preg_match('/^\d{1,5}$/', $order) || (int) $order > 32767) {
                throw new \InvalidArgumentException("Urutan \"{$order}\" harus bilangan bulat 0–32767.");
            }
            $order = (int) $order;
        }

        return [
            'nama' => $name,
            'warna' => $color,
            'ikon' => $icon,
            'urutan' => $order,
            // null = tidak disebut di berkas, jadi nilai yang tersimpan dipertahankan.
            'aktif' => $get('aktif') === null ? null : SpreadsheetIo::boolean($get('aktif'), 'aktif'),
        ];
    }
}
