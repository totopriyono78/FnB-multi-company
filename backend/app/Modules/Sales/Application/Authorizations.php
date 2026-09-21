<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Device;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pemeriksaan pelaku & otorisasi supervisor untuk transaksi POS (FR-AUTH-07, ADR 0004).
 *
 * Bentuk otorisasi dari perangkat:
 *  - online:  {"mode": "online", "authorization_id": "<id dari POST /pos/authorize>"}
 *  - offline: {"mode": "offline", "supervisor_id": "<user id>", "at": "<waktu perangkat>"}
 */
class Authorizations
{
    /** Batas waktu antara otorisasi online dan aksi yang memakainya. */
    public const ONLINE_VALID_MINUTES = 10;

    /** Otorisasi online yang dipakai transaksi dari antrian offline paling lama berumur ini (jam server). */
    public const ONLINE_MAX_QUEUE_HOURS = 24;

    public function __construct(
        private readonly AccessScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    /** Kasir/pelaku harus anggota aktif company, punya izin, dan cakupan outlet. */
    public function staff(string $userId, Outlet $outlet, string $permission, string $field = 'cashier_id'): User
    {
        if (! Str::isUuid($userId)) {
            throw new SalesException('STAFF_NOT_ALLOWED', 'Staf tidak berwenang melakukan aksi ini di outlet tersebut.', 403, field: $field);
        }
        $member = CompanyUser::query()->with('user')->where('user_id', $userId)->where('is_active', true)->first();
        $user = $member?->user;
        if (! $user instanceof User || ! $user->can($permission) || ! $this->scope->allowsOutlet($user, $outlet)) {
            throw new SalesException('STAFF_NOT_ALLOWED', 'Staf tidak berwenang melakukan aksi ini di outlet tersebut.', 403, field: $field);
        }

        return $user;
    }

    public function userCan(User $user, string $permission, Outlet $outlet): bool
    {
        return $user->can($permission) && $this->scope->allowsOutlet($user, $outlet);
    }

    /**
     * Kewenangan pelaku sendiri hanya diakui bila pelaku itulah yang login PIN pada permintaan ini.
     * Kiriman dengan token perangkat (antrian offline) atau oleh kasir lain wajib membawa otorisasi,
     * sehingga ID manajer yang diketik perangkat tidak otomatis memberi hak manajer.
     */
    public function selfAuthorized(User $actor, string $permission, Outlet $outlet): bool
    {
        return $this->verifiedActorId() === $actor->id && $this->userCan($actor, $permission, $outlet);
    }

    public function verifiedActorId(): ?string
    {
        $id = app()->bound('request') ? request()->attributes->get('pos_user_id') : null;

        return is_string($id) ? $id : null;
    }

    public function maxDiscount(User $user): string
    {
        $max = '0';
        foreach ($user->roles as $role) {
            if ($role instanceof Role && BigDecimal::of((string) $role->max_discount_percent)->compareTo(BigDecimal::of($max)) > 0) {
                $max = (string) $role->max_discount_percent;
            }
        }

        return $max;
    }

    /**
     * Validasi otorisasi supervisor; mengembalikan [pemberi otorisasi, offline?].
     *
     * `$expect` mencocokkan konteks yang disetujui supervisor (reference_id, amount, discount_percent) bila tercatat.
     *
     * @param  array<string, mixed>|null  $auth
     * @param  array{reference_id?: string, amount?: string, discount_percent?: string}  $expect
     * @return array{0: User, 1: bool}
     */
    public function verify(?array $auth, string $action, Device $device, CarbonImmutable $actionAt, string $field = 'authorization', ?string $reference = null, array $expect = []): array
    {
        $permission = PermissionRegistry::SUPERVISOR_ACTIONS[$action] ?? null;
        if ($permission === null || $auth === null) {
            throw new SalesException('AUTHORIZATION_REQUIRED', 'Aksi ini memerlukan otorisasi supervisor.', 403, field: $field);
        }
        $outlet = $device->outlet;

        if (($auth['mode'] ?? null) === 'online') {
            $id = (string) ($auth['authorization_id'] ?? '');
            if (! Str::isUuid($id)) {
                throw new SalesException('AUTHORIZATION_INVALID', 'Otorisasi supervisor tidak ditemukan, sudah kedaluwarsa, atau untuk aksi lain.', 403, field: $field);
            }
            $log = AuditLog::query()
                ->where('id', $id)
                ->where('action', 'pos.authorization_granted')
                ->where('device_id', $device->id)
                ->first();
            $grantedAction = $log?->metadata['action'] ?? null;
            $okAction = $grantedAction === $action || ($action === 'refund' && $grantedAction === 'void');
            // Dekat dengan waktu aksi (jam perangkat) dan tidak lebih tua dari batas antrian di jam server.
            $fresh = $log !== null
                && abs($log->created_at->diffInMinutes($actionAt, false)) <= self::ONLINE_VALID_MINUTES
                && $log->created_at->greaterThanOrEqualTo(now()->subHours(self::ONLINE_MAX_QUEUE_HOURS));
            if ($log === null || ! $okAction || ! $fresh || $log->authorized_by === null || ! $this->contextMatches($log->metadata ?? [], $expect)) {
                throw new SalesException('AUTHORIZATION_INVALID', 'Otorisasi supervisor tidak ditemukan, sudah kedaluwarsa, atau untuk aksi lain.', 403, field: $field);
            }
            $supervisor = $this->staff((string) $log->authorized_by, $outlet, $permission, $field);
            $this->consume($log, $action, $reference, $field);

            return [$supervisor, false];
        }

        if (($auth['mode'] ?? null) === 'offline' && is_string($auth['supervisor_id'] ?? null)) {
            // Belum ada bukti PIN bertanda tangan (Tahap 6): diterima hanya dengan tanda offline_authorization
            // agar penjualan offline tidak hilang, dan wajib ditinjau di back-office.
            // PIN diverifikasi di perangkat saat offline; server memastikan kewenangan & menandai untuk ditinjau.
            $supervisor = $this->staff($auth['supervisor_id'], $outlet, $permission, $field);

            return [$supervisor, true];
        }

        throw new SalesException('AUTHORIZATION_INVALID', 'Format otorisasi supervisor tidak dikenal.', 422, field: $field);
    }

    /**
     * Satu otorisasi online hanya untuk satu transaksi. Pengiriman ulang entitas yang sama (referensi sama) tetap diterima.
     */
    private function consume(AuditLog $grant, string $action, ?string $reference, string $field): void
    {
        $reference ??= 'unspecified';
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pos-authorization:'.$grant->id]);

        $used = AuditLog::query()
            ->where('auditable_type', 'AuditLog')
            ->where('auditable_id', $grant->id)
            ->where('action', 'pos.authorization_used')
            ->get(['metadata']);
        foreach ($used as $use) {
            if (($use->metadata['reference'] ?? null) !== $reference) {
                throw new SalesException('AUTHORIZATION_USED', 'Otorisasi supervisor ini sudah dipakai untuk transaksi lain.', 403, field: $field);
            }
        }
        if ($used->isNotEmpty()) {
            return;
        }

        $this->audit->log('pos.authorization_used', $grant, authorizedBy: $grant->authorized_by, metadata: ['action' => $action, 'reference' => $reference]);
    }

    /**
     * @param  array<string, mixed>  $granted
     * @param  array{reference_id?: string, amount?: string, discount_percent?: string}  $expect
     */
    private function contextMatches(array $granted, array $expect): bool
    {
        // Otorisasi untuk transaksi tertentu wajib menyebut transaksinya (reference_id) saat diminta.
        if (isset($expect['reference_id']) && (string) ($granted['reference_id'] ?? '') !== $expect['reference_id']) {
            return false;
        }
        if (isset($granted['amount'], $expect['amount'])
            && ! BigDecimal::of((string) $granted['amount'])->isEqualTo(BigDecimal::of($expect['amount']))) {
            return false;
        }
        if (isset($granted['discount_percent'], $expect['discount_percent'])
            && BigDecimal::of($expect['discount_percent'])->isGreaterThan(BigDecimal::of((string) $granted['discount_percent']))) {
            return false;
        }

        return true;
    }
}
