<?php

namespace App\Modules\Shared\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Nilai uang immutable dengan presisi desimal (tanpa float).
 *
 * Perhitungan memakai presisi penuh; pembulatan ke 2 desimal (NUMERIC(18,2))
 * hanya dilakukan lewat rounded() atau saat nilai disimpan (BR-04).
 */
final class Money implements JsonSerializable, Stringable
{
    public const SCALE = 2;

    private function __construct(
        private readonly BigDecimal $amount,
        private readonly string $currency,
    ) {}

    public static function of(string|int|BigDecimal $amount, string $currency = 'IDR'): self
    {
        if (is_string($amount) && ! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Nominal uang tidak valid: {$amount}");
        }

        return new self(BigDecimal::of($amount), strtoupper($currency));
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return self::of(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function multipliedBy(string|int $factor): self
    {
        return new self($this->amount->multipliedBy(BigDecimal::of($factor)), $this->currency);
    }

    /** Persentase, mis. percentage('10') = 10% dari nilai ini (presisi penuh). */
    public function percentage(string|int $percent): self
    {
        return new self(
            $this->amount->multipliedBy(BigDecimal::of($percent))->dividedBy(100, 10, RoundingMode::HALF_UP),
            $this->currency,
        );
    }

    /** Bulatkan ke kelipatan tertentu, mis. Rp100 (aturan pembulatan outlet). */
    public function roundToUnit(int $unit, string $mode = 'nearest'): self
    {
        if ($unit <= 0) {
            return $this->rounded();
        }

        $rounding = match ($mode) {
            'nearest' => RoundingMode::HALF_UP,
            'up' => RoundingMode::CEILING,
            'down' => RoundingMode::FLOOR,
            default => throw new InvalidArgumentException("Mode pembulatan tidak dikenal: {$mode}"),
        };

        $units = $this->amount->dividedBy($unit, 0, $rounding);

        return new self($units->multipliedBy($unit)->toScale(self::SCALE), $this->currency);
    }

    public function rounded(): self
    {
        return new self($this->amount->toScale(self::SCALE, RoundingMode::HALF_UP), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->isEqualTo($other->amount);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** Nilai untuk disimpan ke kolom NUMERIC(18,2). */
    public function toDecimalString(): string
    {
        return (string) $this->amount->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /** Format tampilan lokal: "Rp 18.000" (IDR tanpa desimal, SRS §2.6 butir 8). */
    public function format(): string
    {
        $value = $this->amount->toScale(0, RoundingMode::HALF_UP);
        $negative = $value->isNegative();
        $digits = (string) $value->abs();
        $grouped = ltrim(strrev(implode('.', str_split(strrev($digits), 3))), '.');
        $prefix = $this->currency === 'IDR' ? 'Rp ' : $this->currency.' ';

        return ($negative ? '-' : '').$prefix.$grouped;
    }

    public function jsonSerialize(): string
    {
        return $this->toDecimalString();
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Mata uang berbeda tidak dapat dihitung bersama.');
        }
    }
}
