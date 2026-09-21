<?php

namespace App\Modules\Reporting\Application;

use Carbon\CarbonImmutable;

/**
 * Filter laporan yang sudah divalidasi: periode hari bisnis + outlet dalam cakupan user.
 *
 * `outletIds` selalu hasil irisan cakupan user dengan pilihan brand/outlet sehingga query laporan tidak perlu
 * memeriksa hak akses lagi.
 */
final class ReportFilter
{
    public const MAX_DAYS = 366;

    /**
     * @param  list<string>  $outletIds
     * @param  array<string, string>  $labels  keterangan filter untuk judul ekspor (mis. Outlet => nama)
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $outletIds,
        public readonly ?string $brandId = null,
        public readonly ?string $outletId = null,
        public readonly array $labels = [],
        public readonly ?CarbonImmutable $until = null,
    ) {}

    /** Salinan dengan batas waktu transaksi (untuk perbandingan "sampai jam yang sama"). */
    public function until(?CarbonImmutable $until): self
    {
        return new self($this->from, $this->to, $this->outletIds, $this->brandId, $this->outletId, $this->labels, $until);
    }

    /** @param list<string> $outletIds */
    public function withPeriod(CarbonImmutable $from, CarbonImmutable $to, ?array $outletIds = null): self
    {
        return new self($from, $to, $outletIds ?? $this->outletIds, $this->brandId, $this->outletId, $this->labels, $this->until);
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->to->format('Y-m-d');
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** Periode sebelumnya dengan panjang yang sama (untuk perbandingan). */
    public function previous(): self
    {
        $days = $this->days();

        return $this->withPeriod($this->from->subDays($days), $this->from->subDay());
    }

    public function periodLabel(): string
    {
        $fmt = fn (CarbonImmutable $d) => $d->locale('id')->translatedFormat('j M Y');

        return $this->from->equalTo($this->to) ? $fmt($this->from) : $fmt($this->from).' – '.$fmt($this->to);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date_from' => $this->fromDate(),
            'date_to' => $this->toDate(),
            'brand_id' => $this->brandId,
            'outlet_id' => $this->outletId,
            'outlet_count' => count($this->outletIds),
        ];
    }
}
