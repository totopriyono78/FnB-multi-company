<?php

namespace App\Modules\Tenancy\Domain;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu Pairing',
            self::Active => 'Aktif',
            self::Revoked => 'Dinonaktifkan',
        };
    }
}
