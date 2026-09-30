<?php

namespace App\Modules\Accounting\Application;

use RuntimeException;

/** Ditolak karena aturan akuntansi (bukan galat teknis). */
class AccountingException extends RuntimeException
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
