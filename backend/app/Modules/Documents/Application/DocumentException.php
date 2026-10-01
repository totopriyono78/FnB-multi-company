<?php

namespace App\Modules\Documents\Application;

use RuntimeException;

/** Ditolak karena aturan dokumen (bukan galat teknis). */
class DocumentException extends RuntimeException
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
