<?php

namespace App\Modules\Treasury\Application;

use RuntimeException;

/** Ditolak karena aturan kas/bank, hutang, atau piutang (bukan galat teknis). */
class TreasuryException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
