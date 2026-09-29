<?php

namespace App\Modules\Catalog\Application;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Pembacaan & penulisan berkas Excel/CSV untuk impor-ekspor katalog.
 *
 * Dipisahkan dari MenuSpreadsheet saat impor kategori dibangun (28 Sep 2026). Sebelumnya logika
 * ini privat di sana, dan menyalinnya ke kelas kedua berarti dua tempat yang harus ikut berubah
 * setiap kali ada berkas aneh dari lapangan — pemisah titik koma, BOM dari Excel Indonesia,
 * tanggal yang terbaca sebagai objek. Satu tempat saja.
 */
class SpreadsheetIo
{
    /**
     * Baca sheet pertama menjadi baris-baris berkunci nama kolom.
     *
     * @param  list<string>  $required  kolom yang wajib ada di baris header
     * @return list<array<string, string>>
     */
    public static function read(string $path, string $extension, array $required, int $maxRows): array
    {
        $extension = strtolower($extension);
        if ($extension === 'csv' || $extension === 'txt') {
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = self::detectDelimiter($path);
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
                $values = array_map(
                    fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : trim((string) $v),
                    $row->toArray()
                );

                if ($headers === null) {
                    // Excel versi Indonesia kerap menulis BOM di sel pertama; tanpa dibuang,
                    // nama kolom pertama tidak pernah cocok dan pengguna bingung sendiri.
                    $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0] ?? '');
                    $headers = array_map(fn ($h) => Str::snake(mb_strtolower(trim((string) $h))), $values);
                    $missing = array_diff($required, $headers);
                    if ($missing !== []) {
                        $reader->close();

                        throw ValidationException::withMessages([
                            'file' => 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Unduh template dari menu Ekspor.',
                        ]);
                    }

                    continue;
                }

                if (implode('', $values) === '') {
                    continue;
                }

                if (count($rows) >= $maxRows) {
                    $reader->close();

                    throw ValidationException::withMessages(['file' => 'Maksimal '.$maxRows.' baris per impor.']);
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
     * Semua sel ditulis sebagai teks agar nilai seperti "=HYPERLINK(...)" tidak menjadi rumus
     * saat berkas dibuka (formula injection).
     *
     * @param  list<string>  $values
     */
    public static function textRow(array $values): Row
    {
        return new Row(array_map(fn (string $v) => new StringCell($v, null), $values));
    }

    /** Buat path file sementara berakhiran .xlsx. */
    public static function tempXlsx(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new \RuntimeException('Tidak dapat membuat file sementara.');
        }
        @unlink($base);

        return $base.'.xlsx';
    }

    /** Kolom ya/tidak. Kosong berarti `$default` — bukan "tidak", agar kolom kosong tidak mematikan data. */
    public static function boolean(?string $value, string $column, bool $default = true): bool
    {
        $v = mb_strtolower(trim((string) $value));
        if ($v === '') {
            return $default;
        }

        if (in_array($v, ['ya', '1', 'y', 'true', 'aktif'], true)) {
            return true;
        }

        if (in_array($v, ['tidak', '0', 'n', 'false', 'nonaktif'], true)) {
            return false;
        }

        throw new \InvalidArgumentException("Kolom {$column} diisi \"ya\" atau \"tidak\".");
    }

    private static function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);
        if ($handle !== false) {
            fclose($handle);
        }

        return substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    }
}
