<?php

namespace App\Modules\Reporting\Application;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Bentuk tabel laporan yang sama untuk halaman, API, Excel, dan PDF (ADR 0006).
 *
 * Nilai sel disimpan mentah: uang & angka sebagai string desimal, persen sebagai string desimal (mis. "31.25"),
 * teks apa adanya. Pemformatan dilakukan oleh penyaji.
 */
final class ReportTable
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    /**
     * @param  array<string, array{label: string, type: string}>  $columns
     * @param  list<array<string, string|int|null>>  $rows
     * @param  array<string, string|int|null>|null  $totals
     * @param  array<string, string>  $filters  keterangan filter (label => nilai)
     * @param  list<array{label: string, value: string, type: string}>  $summary  angka ringkasan di atas tabel
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly array $filters = [],
        public readonly array $summary = [],
        public readonly array $notes = [],
        public readonly ?string $subtitle = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'filters' => $this->filters,
            'summary' => $this->summary,
            'columns' => array_map(fn (string $key, array $c) => ['key' => $key] + $c, array_keys($this->columns), $this->columns),
            'rows' => $this->rows,
            'totals' => $this->totals,
            'notes' => $this->notes,
        ];
    }

    public static function format(string|int|null $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($type) {
            self::MONEY => self::rupiah((string) $value),
            self::NUMBER => self::number((string) $value),
            self::PERCENT => self::number((string) $value, 1).'%',
            default => (string) $value,
        };
    }

    public static function rupiah(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');

        return ($negative ? '-' : '').'Rp'.self::number($abs, 2, true);
    }

    /** Format angka Indonesia; desimal nol di belakang dibuang. */
    public static function number(string $value, int $maxDecimals = 3, bool $moneyDecimals = false): string
    {
        $value = (string) BigDecimal::of($value)->toScale($maxDecimals, RoundingMode::HALF_UP);
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        [$int, $dec] = array_pad(explode('.', $abs, 2), 2, '');
        $dec = rtrim($dec, '0');
        if ($moneyDecimals && $dec !== '' && strlen($dec) === 1) {
            $dec .= '0';
        }
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $out = strrev(implode('.', str_split(strrev($int), 3)));
        $text = $dec === '' ? $out : $out.','.$dec;

        return ($negative && trim($text, '0,.') !== '' ? '-' : '').$text;
    }
}
