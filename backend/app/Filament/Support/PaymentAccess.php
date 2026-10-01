<?php

namespace App\Filament\Support;

use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use Brick\Math\BigDecimal;

/**
 * Siapa boleh berbuat apa pada dokumen pembayaran.
 *
 * Tiga izin, tiga pertanyaan berbeda:
 *
 * - `payment.view`    — boleh melihat dokumen pembayaran entitas ini.
 * - `payment.request` — boleh membuat dan mengajukan SPPK.
 * - `payment.pay`     — boleh menerbitkan advis bayar (mengeluarkan uangnya).
 *
 * Kewenangan **menyetujui** sengaja bukan izin: yang menentukan siapa boleh menandatangani adalah
 * matriks batas wewenang, lewat peran, pada nilai tertentu. Kalau persetujuan dijadikan izin, orang
 * yang diberi izin itu bisa menyetujui nilai berapa pun — justru kebalikan dari gunanya batas wewenang.
 */
final class PaymentAccess
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canView(): bool
    {
        return self::user()?->can('payment.view') === true;
    }

    public static function canRequest(): bool
    {
        return self::user()?->can('payment.request') === true;
    }

    public static function canPay(): bool
    {
        return self::user()?->can('payment.pay') === true;
    }

    /**
     * Apakah orang yang sedang masuk adalah penandatangan tingkat berikutnya pengajuan ini?
     *
     * Dipakai untuk menyembunyikan tombol "Setujui" dari orang yang pasti ditolak layanannya —
     * tombol yang selalu gagal membuat orang mengira sistemnya rusak. Penjagaan sebenarnya tetap
     * ada di PaymentRequestService; ini hanya supaya layarnya jujur.
     */
    public static function canApprove(PaymentRequest $request): bool
    {
        $user = self::user();
        $level = $request->nextLevel();
        if ($user === null || $level === null || $request->requested_by === $user->id) {
            return false;
        }
        if ($request->approvals()->where('approved_by', $user->id)->exists()) {
            return false;
        }

        try {
            $role = app(ApprovalMatrix::class)
                ->roleForLevel(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of($request->amount), $level);
        } catch (\Throwable) {
            // Matriksnya belum/tidak lagi memuat nilai ini. Tombolnya disembunyikan dan pesannya
            // muncul di layar matriks, bukan sebagai galat di sini.
            return false;
        }

        return $user->hasRole($role);
    }
}
