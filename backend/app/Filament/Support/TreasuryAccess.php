<?php

namespace App\Filament\Support;

use App\Modules\Identity\Domain\Models\User;

/**
 * Siapa boleh berbuat apa pada kas, bank, hutang & piutang.
 *
 * `treasury.reconcile` sengaja terpisah dari `treasury.manage`: mencatat uang keluar dan menyatakan
 * "catatan ini sudah cocok dengan rekening koran" adalah dua pekerjaan yang saling memeriksa.
 * Entitas yang orangnya cukup bisa memisahkannya; yang tidak, memberikan keduanya ke orang yang
 * sama — tetapi pilihannya ada, dan itulah gunanya dipisah.
 */
final class TreasuryAccess
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canView(): bool
    {
        return self::user()?->can('treasury.view') === true;
    }

    public static function canManage(): bool
    {
        return self::user()?->can('treasury.manage') === true;
    }

    public static function canReconcile(): bool
    {
        return self::user()?->can('treasury.reconcile') === true;
    }
}
