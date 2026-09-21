<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use App\Modules\Catalog\Domain\Models\MenuCategory;
use App\Modules\Tenancy\Domain\Models\Brand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Impor/ekspor menu via Excel atau CSV (FR-MENU-09).
 *
 * Kolom: kategori, sku, nama, nama_singkat, harga, varian, stasiun, barcode, deskripsi, aktif.
 * Varian ditulis "Regular=18000; Large=22000". Impor bersifat semua-atau-tidak-sama-sekali.
 */
class MenuSpreadsheet
{
    public const HEADERS = ['kategori', 'sku', 'nama', 'nama_singkat', 'harga', 'varian', 'stasiun', 'barcode', 'deskripsi', 'aktif'];

    public const MAX_ROWS = 5000;

    public function __construct(private readonly ItemWriter $writer) {}

    /**
     * @return array{created: int, updated: int, categories_created: int, errors: list<array{row: int, message: string}>, dry_run: bool}
     */
    public function import(Brand $brand, string $path, string $extension, bool $dryRun = false): array
    {
        $rows = $this->read($path, $extension);
        $errors = [];
        $parsed = [];
        $skus = [];

        foreach ($rows as $n => $row) {
            $line = $n + 2;
            try {
                $item = $this->parseRow($row);
                $key = mb_strtolower($item['sku']);
                if (isset($skus[$key])) {
                    throw new \InvalidArgumentException("SKU {$item['sku']} muncul lebih dari sekali (baris {$skus[$key]}).");
                }
                $skus[$key] = $line;
                $parsed[$line] = $item;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['row' => $line, 'message' => $e->getMessage()];
            }
        }

        if ($parsed === [] && $errors === []) {
            $errors[] = ['row' => 1, 'message' => 'File tidak berisi data menu.'];
        }

        $stations = KitchenStation::query()->get()->flatMap(fn ($s) => [mb_strtolower($s->code) => $s->id, mb_strtolower($s->name) => $s->id]);
        foreach ($parsed as $line => $item) {
            if ($item['stasiun'] !== null && ! isset($stations[mb_strtolower($item['stasiun'])])) {
                $errors[] = ['row' => $line, 'message' => "Stasiun \"{$item['stasiun']}\" tidak dikenal."];
            }
        }

        $existing = Item::query()->with('variants')->where('brand_id', $brand->id)->get()->keyBy(fn ($i) => mb_strtolower($i->sku));
        foreach ($parsed as $line => $item) {
            $current = $existing->get(mb_strtolower($item['sku']));
            if ($current?->isBundle()) {
                $errors[] = ['row' => $line, 'message' => "SKU {$item['sku']} adalah paket; ubah paket dari back-office."];
            }
        }

        $report = ['created' => 0, 'updated' => 0, 'categories_created' => 0, 'errors' => $errors, 'dry_run' => $dryRun];
        if ($errors !== []) {
            usort($report['errors'], fn ($a, $b) => $a['row'] <=> $b['row']);

            return $report;
        }

        DB::beginTransaction();
        try {
            $categories = MenuCategory::query()->where('brand_id', $brand->id)->get()->keyBy(fn ($c) => mb_strtolower($c->name));

            foreach ($parsed as $item) {
                $catKey = mb_strtolower($item['kategori']);
                if (! $categories->has($catKey)) {
                    $categories->put($catKey, MenuCategory::query()->create([
                        'brand_id' => $brand->id, 'name' => $item['kategori'], 'sort_order' => $categories->count(),
                    ]));
                    $report['categories_created']++;
                }

                $current = $existing->get(mb_strtolower($item['sku']));
                $payload = [
                    'brand_id' => $brand->id,
                    'category_id' => $categories->get($catKey)->id,
                    'sku' => $item['sku'],
                    'name' => $item['nama'],
                    'short_name' => $item['nama_singkat'] ?? mb_substr($item['nama'], 0, 24),
                    'base_price' => $item['harga'],
                    'kitchen_station_id' => $item['stasiun'] === null ? null : $stations[mb_strtolower($item['stasiun'])],
                    'barcode' => $item['barcode'],
                    'description' => $item['deskripsi'],
                    'is_active' => $item['aktif'],
                    'variants' => $this->mergeVariants($current, $item['varian']),
                ];
                $this->writer->save($current, $payload);
                $current === null ? $report['created']++ : $report['updated']++;
            }

            // Mode pratinjau: hitung hasil tanpa menyimpan.
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }

    /** Tulis file Excel menu satu brand; mengembalikan path file sementara. */
    public function export(Brand $brand): string
    {
        $base = tempnam(sys_get_temp_dir(), 'menu');
        if ($base === false) {
            throw new \RuntimeException('Tidak dapat membuat file sementara.');
        }
        @unlink($base);
        $path = $base.'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(self::textRow(self::HEADERS));

        Item::query()
            ->with(['category', 'station', 'variants'])
            ->where('brand_id', $brand->id)
            ->where('type', Item::TYPE_SINGLE)
            ->orderBy('category_id')->orderBy('sort_order')->orderBy('name')
            ->chunk(500, function ($items) use ($writer): void {
                foreach ($items as $item) {
                    /** @var Item $item */
                    $writer->addRow(self::textRow([
                        $item->category->name,
                        $item->sku,
                        $item->name,
                        $item->short_name,
                        $this->plainNumber((string) $item->base_price),
                        $item->variants->map(fn ($v) => $v->name.'='.$this->plainNumber((string) $v->price))->implode('; '),
                        $item->station->code ?? '',
                        $item->barcode ?? '',
                        $item->description ?? '',
                        $item->is_active ? 'ya' : 'tidak',
                    ]));
                }
            });

        $writer->close();

        return $path;
    }

    /**
     * Semua sel ditulis sebagai teks agar nilai seperti "=HYPERLINK(...)" tidak menjadi rumus (CSV/formula injection).
     *
     * @param  list<string>  $values
     */
    private static function textRow(array $values): Row
    {
        return new Row(array_map(fn (string $v) => new StringCell($v, null), $values));
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        if ($extension === 'csv' || $extension === 'txt') {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = $this->detectDelimiter($path);
            $reader = new CsvReader($options);
        } elseif ($extension === 'xlsx') {
            $reader = new XlsxReader;
        } else {
            throw ValidationException::withMessages(['file' => 'Format file harus .xlsx atau .csv.']);
        }

        $reader->open($path);
        $headers = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : trim((string) $v), $row->toArray());
                if ($headers === null) {
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0] ?? '');
                    $headers = array_map(fn ($h) => Str::snake(mb_strtolower(trim((string) $h))), $values);
                    $missing = array_diff(['kategori', 'sku', 'nama', 'harga'], $headers);
                    if ($missing !== []) {
                        $reader->close();
                        throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Unduh template dari menu Ekspor.']);
                    }

                    continue;
                }
                if (implode('', $values) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $reader->close();
                    throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris per impor.']);
                }
                $rows[] = array_combine($headers, array_pad(array_slice($values, 0, count($headers)), count($headers), ''));
            }
            break; // hanya sheet pertama
        }
        $reader->close();

        if ($headers === null) {
            throw ValidationException::withMessages(['file' => 'File kosong.']);
        }

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array{kategori: string, sku: string, nama: string, nama_singkat: string|null, harga: string, varian: list<array{name: string, price: string}>, stasiun: string|null, barcode: string|null, deskripsi: string|null, aktif: bool}
     */
    private function parseRow(array $row): array
    {
        $get = fn (string $k) => ($v = trim((string) ($row[$k] ?? ''))) === '' ? null : $v;

        foreach (['kategori' => 'Kategori', 'sku' => 'SKU', 'nama' => 'Nama', 'harga' => 'Harga'] as $key => $label) {
            if ($get($key) === null) {
                throw new \InvalidArgumentException("{$label} wajib diisi.");
            }
        }
        if (! preg_match('/^[A-Za-z0-9\-_.]{1,40}$/', (string) $get('sku'))) {
            throw new \InvalidArgumentException('SKU hanya boleh huruf, angka, titik, garis bawah, atau tanda hubung (maks. 40).');
        }
        foreach (['nama' => 100, 'kategori' => 60, 'nama_singkat' => 24, 'barcode' => 40] as $key => $max) {
            if (mb_strlen((string) $get($key)) > $max) {
                throw new \InvalidArgumentException("Kolom {$key} maksimal {$max} karakter.");
            }
        }

        $variants = [];
        foreach (array_filter(array_map('trim', explode(';', (string) $get('varian')))) as $chunk) {
            if (! str_contains($chunk, '=')) {
                throw new \InvalidArgumentException("Format varian \"{$chunk}\" salah. Contoh: Regular=18000; Large=22000");
            }
            [$name, $price] = array_map('trim', explode('=', $chunk, 2));
            if ($name === '' || mb_strlen($name) > 40) {
                throw new \InvalidArgumentException('Nama varian wajib diisi (maks. 40 karakter).');
            }
            $variants[] = ['name' => $name, 'price' => $this->money($price, "harga varian {$name}")];
        }

        $active = mb_strtolower((string) ($get('aktif') ?? 'ya'));
        if (! in_array($active, ['ya', 'tidak', '1', '0', 'y', 'n', 'true', 'false', 'aktif', 'nonaktif'], true)) {
            throw new \InvalidArgumentException('Kolom aktif diisi "ya" atau "tidak".');
        }

        return [
            'kategori' => (string) $get('kategori'),
            'sku' => (string) $get('sku'),
            'nama' => (string) $get('nama'),
            'nama_singkat' => $get('nama_singkat'),
            'harga' => $this->money((string) $get('harga'), 'harga'),
            'varian' => $variants,
            'stasiun' => $get('stasiun'),
            'barcode' => $get('barcode'),
            'deskripsi' => $get('deskripsi') === null ? null : mb_substr((string) $get('deskripsi'), 0, 1000),
            'aktif' => in_array($active, ['ya', '1', 'y', 'true', 'aktif'], true),
        ];
    }

    /**
     * Terima "18000", "18.000", "Rp 18.000", "18000.50", "18.000,50".
     */
    private function money(string $value, string $label): string
    {
        $v = preg_replace('/^rp\.?\s*/i', '', str_replace(' ', '', $value)) ?? '';
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $v)) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $v)) {
            $v = str_replace(',', '.', $v);
        } elseif (! preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
            throw new \InvalidArgumentException("Nilai {$label} \"{$value}\" tidak valid. Contoh: 18000 atau 18.000");
        }
        if (strlen(explode('.', $v)[0]) > 12) {
            throw new \InvalidArgumentException("Nilai {$label} terlalu besar.");
        }

        return $v;
    }

    /**
     * Pertahankan id varian lama bila namanya sama agar riwayat harga tetap nyambung.
     *
     * @param  list<array{name: string, price: string}>  $variants
     * @return list<array<string, mixed>>
     */
    private function mergeVariants(?Item $current, array $variants): array
    {
        $old = $current?->variants->keyBy(fn ($v) => mb_strtolower($v->name)) ?? collect();

        return array_map(fn ($v) => array_filter([
            'id' => $old->get(mb_strtolower($v['name']))?->id,
            'name' => $v['name'],
            'price' => $v['price'],
        ], fn ($x) => $x !== null), $variants);
    }

    private function plainNumber(string $value): string
    {
        return str_ends_with($value, '.00') ? substr($value, 0, -3) : $value;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
