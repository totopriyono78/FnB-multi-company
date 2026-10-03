<?php

namespace App\Filament\Support;

use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;

/**
 * Siapa boleh berbuat apa pada grup & konsolidasi.
 *
 * Satu syarat tambahan di luar izin: **entitas yang sedang aktif harus memegang grup.** Seluruh
 * layar di modul ini hilang dari menu entitas biasa, bukan karena disembunyikan satu per satu,
 * tetapi karena `Group::query()` memang tidak mengembalikan apa pun di sana — baris grup milik
 * entitas holding, dan RLS yang menentukannya. Jadi menu yang kosong di sini berarti data yang
 * kosong, bukan tampilan yang disembunyikan; keduanya mudah tertukar dan hanya yang pertama aman.
 */
final class ConsolidationAccess
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function group(): ?Group
    {
        return app(GroupService::class)->currentGroup();
    }

    public static function isHolding(): bool
    {
        return self::group() !== null;
    }

    public static function canView(): bool
    {
        return self::user()?->can('consolidation.view') === true && self::isHolding();
    }

    public static function canManage(): bool
    {
        return self::user()?->can('consolidation.manage') === true && self::isHolding();
    }

    /**
     * Layar grup holding: satu-satunya yang boleh dibuka sebelum grupnya ada, karena kalau tidak
     * tidak akan pernah ada grup yang bisa dibuat dari layar sama sekali.
     *
     * Dijaga `group.manage` — izin STRUKTUR ENTITAS, bukan `consolidation.manage`. Menyatakan
     * "entitas-entitas ini satu grup" adalah keputusan pemilik, sedangkan menarik saldo lintas
     * entitas dan mengentri eliminasi adalah pekerjaan finance. Versi pertama menyatukan keduanya,
     * dan akibatnya pemilik tidak punya satu pun pintu untuk membuat grupnya sendiri.
     */
    public static function canManageGroup(): bool
    {
        return self::user()?->can('group.manage') === true;
    }

    /**
     * Entitas yang sudah menjadi anggota grup entitas lain: layar grup tidak punya apa pun untuk
     * dikerjakannya, jadi menunya ditutup alih-alih memperlihatkan daftar kosong beserta tombol
     * buat-grup yang akan ditolak layanannya.
     */
    public static function isMemberOfOtherGroup(): bool
    {
        return app(GroupService::class)->memberOfOtherGroup();
    }
}
