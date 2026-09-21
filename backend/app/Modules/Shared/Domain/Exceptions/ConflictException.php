<?php

namespace App\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/** Aksi ditolak karena bentrok dengan data lain (HTTP 409). Pesan ditampilkan ke pengguna. */
class ConflictException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
