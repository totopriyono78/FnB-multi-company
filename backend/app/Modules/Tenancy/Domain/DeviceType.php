<?php

namespace App\Modules\Tenancy\Domain;

enum DeviceType: string
{
    case Pos = 'pos';
    case Kds = 'kds';
    case CustomerDisplay = 'customer_display';
    case Kiosk = 'kiosk';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'Kasir (POS)',
            self::Kds => 'Layar Dapur (KDS)',
            self::CustomerDisplay => 'Layar Pelanggan',
            self::Kiosk => 'Kiosk',
        };
    }
}
