<?php

namespace App\Modules\Tenancy\Domain;

enum CompanyStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Masa Coba',
            self::Active => 'Aktif',
            self::Suspended => 'Ditangguhkan',
        };
    }
}
