<?php

namespace App\Modules\Sales\Application;

use RuntimeException;

/**
 * Transaksi ditolak karena aturan bisnis. `retryable` = boleh dikirim ulang nanti tanpa diubah
 * (mis. shift belum diterima server atau QRIS belum terkonfirmasi).
 */
class SalesException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly bool $retryable = false,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function retry(string $code, string $message): self
    {
        return new self($code, $message, 409, true);
    }
}
