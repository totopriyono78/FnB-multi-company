<?php

namespace App\Filament\Support;

use App\Modules\Identity\Domain\Models\User;

/**
 * Siapa boleh melihat dan mengubah pembukuan.
 *
 * Memakai izin yang sudah ada di matriks SRS §12.1 (`accounting.view` / `accounting.manage`,
 * dan yang kedua sudah mencakup yang pertama) — tidak ada izin baru yang ditambahkan diam-diam.
 * Pemisahan maker–checker (pengaju ≠ pemosting) menyusul bersama alur dokumen DOC-02.
 */
final class AccountingAccess
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canView(): bool
    {
        return self::user()?->can('accounting.view') === true;
    }

    public static function canManage(): bool
    {
        return self::user()?->can('accounting.manage') === true;
    }
}
