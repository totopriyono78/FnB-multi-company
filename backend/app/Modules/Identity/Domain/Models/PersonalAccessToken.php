<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Tenancy\Application\TenantContext;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token Sanctum dengan pengikatan company & device (FR-AUTH-10).
 *
 * @property string|null $company_id
 * @property string|null $device_id
 */
class PersonalAccessToken extends SanctumToken
{
    /**
     * Token dicari sebelum tenant diketahui, jadi dibaca dalam mode sistem
     * lalu pemiliknya (user/device) dimuat saat itu juga.
     *
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        return app(TenantContext::class)->runAsSystem(function () use ($token) {
            $found = parent::findToken($token);
            $found?->loadMissing('tokenable');

            return $found;
        });
    }
}
