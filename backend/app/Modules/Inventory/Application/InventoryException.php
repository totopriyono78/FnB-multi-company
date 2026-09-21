<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Shared\Domain\Exceptions\ConflictException;

/**
 * Aksi inventory/pembelian ditolak karena aturan bisnis (HTTP 409 / 422).
 * `field` diisi bila galat terkait input tertentu.
 */
class InventoryException extends ConflictException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly ?string $field = null,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode, $message);
    }
}
