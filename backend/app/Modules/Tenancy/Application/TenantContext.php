<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Shared\Infrastructure\Database\Rls;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Konteks tenant untuk request/job yang sedang berjalan (NFR-SEC-01).
 *
 * Tiga mode:
 *  - none   : default deny. Global scope mengembalikan 0 baris, koneksi DB memakai role RLS tanpa company.
 *  - tenant : hanya data company aktif (global scope + RLS).
 *  - system : lintas tenant, HANYA untuk proses internal yang eksplisit (login, pairing, job platform).
 */
final class TenantContext
{
    private const MODE_NONE = 'none';

    private const MODE_TENANT = 'tenant';

    private const MODE_SYSTEM = 'system';

    private string $mode = self::MODE_NONE;

    private ?string $companyId = null;

    /** Apakah koneksi sedang memakai role RLS (bukan role pemilik). */
    private bool $dbRestricted = false;

    /** True bila pemulihan konteks gagal sehingga status role koneksi tidak pasti. */
    private bool $connectionStateUnknown = false;

    /** Apakah koneksi seharusnya memakai role RLS (tenant atau mode dibatasi). */
    private bool $intendsRestriction = false;

    public function companyId(): ?string
    {
        return $this->mode === self::MODE_TENANT ? $this->companyId : null;
    }

    public function hasTenant(): bool
    {
        return $this->mode === self::MODE_TENANT;
    }

    public function isSystem(): bool
    {
        return $this->mode === self::MODE_SYSTEM;
    }

    public function requireCompanyId(): string
    {
        return $this->companyId() ?? throw new \LogicException('Konteks company belum ditetapkan.');
    }

    public function setTenant(string $companyId): void
    {
        $this->mode = self::MODE_TENANT;
        $this->companyId = $companyId;
        $this->intendsRestriction = true;
        $this->applyToDatabase();
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        Log::withContext(['company_id' => $companyId]);
    }

    /** Mode tanpa tenant yang tetap dibatasi RLS (dipakai di awal setiap request API). */
    public function restrict(): void
    {
        $this->mode = self::MODE_NONE;
        $this->companyId = null;
        $this->intendsRestriction = true;
        $this->applyToDatabase();
    }

    /** Kembalikan ke kondisi awal dan lepaskan role RLS dari koneksi (akhir request). */
    public function reset(): void
    {
        $this->mode = self::MODE_NONE;
        $this->companyId = null;
        // Selalu dijalankan: status role bisa berubah tanpa sepengetahuan objek ini bila transaksi di-rollback.
        if (Rls::isPgsql()) {
            DB::statement('RESET ROLE');
            DB::select('SELECT set_config(?, ?, false)', [Rls::SETTING, '']);
        }
        $this->dbRestricted = false;
        $this->connectionStateUnknown = false;
        $this->intendsRestriction = false;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsTenant(string $companyId, Closure $callback): mixed
    {
        $previous = [$this->mode, $this->companyId, $this->dbRestricted];

        return $this->guarded($previous, function () use ($companyId, $callback): mixed {
            $this->setTenant($companyId);

            return $callback();
        });
    }

    /**
     * Jalankan callback lintas tenant. Gunakan hanya untuk proses internal yang sudah divalidasi.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        $previous = [$this->mode, $this->companyId, $this->dbRestricted];

        return $this->guarded($previous, function () use ($callback): mixed {
            $this->mode = self::MODE_SYSTEM;
            $this->companyId = null;
            $this->intendsRestriction = false;
            $this->applyToDatabase();

            return $callback();
        });
    }

    /**
     * Menjalankan callback lalu memulihkan konteks. Bila callback gagal dan pemulihan juga gagal
     * (mis. transaksi PostgreSQL sudah batal), galat asli yang dilempar dan status koneksi ditandai
     * perlu diterapkan ulang sepenuhnya pada pemanggilan berikutnya.
     *
     * @template T
     *
     * @param  array{0: string, 1: ?string, 2: bool}  $previous
     * @param  Closure(): T  $callback
     * @return T
     */
    private function guarded(array $previous, Closure $callback): mixed
    {
        try {
            $result = $callback();
        } catch (Throwable $e) {
            try {
                $this->restore(...$previous);
            } catch (Throwable $restoreError) {
                $this->connectionStateUnknown = true;
                report($restoreError);
            }

            throw $e;
        }

        $this->restore(...$previous);

        return $result;
    }

    private function restore(string $mode, ?string $companyId, bool $wasRestricted): void
    {
        $this->mode = $mode;
        $this->companyId = $companyId;
        $this->intendsRestriction = $wasRestricted || $mode === self::MODE_TENANT;

        try {
            if ($this->intendsRestriction) {
                $this->applyToDatabase();
            } elseif (($this->dbRestricted || $this->connectionStateUnknown) && Rls::isPgsql()) {
                // Kembalikan koneksi ke kondisi sebelum callback (mis. proses console tanpa pembatasan).
                DB::statement('RESET ROLE');
                DB::select('SELECT set_config(?, ?, false)', [Rls::SETTING, '']);
                $this->dbRestricted = false;
                $this->connectionStateUnknown = false;
            }
        } finally {
            // Tim permission selalu dikembalikan walau pemulihan koneksi gagal.
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        }
    }

    /**
     * Seperti resync(), tetapi tidak pernah melempar galat (dipanggil dari listener event database agar
     * tidak menutupi galat asli). Bila gagal, status koneksi ditandai tidak pasti dan diterapkan ulang
     * sepenuhnya pada perubahan konteks berikutnya.
     */
    public function resyncSafely(): void
    {
        try {
            $this->resync();
        } catch (Throwable $e) {
            $this->connectionStateUnknown = true;
            report($e);
        }
    }

    /**
     * Terapkan ulang status koneksi setelah koneksi dibuat ulang atau transaksi dibatalkan
     * (keduanya dapat mengembalikan role/setting PostgreSQL tanpa sepengetahuan objek ini).
     */
    public function resync(): void
    {
        if (! Rls::isPgsql() || (! $this->intendsRestriction && ! $this->dbRestricted && $this->mode !== self::MODE_SYSTEM)) {
            return;
        }

        $this->connectionStateUnknown = true;
        if ($this->intendsRestriction) {
            $this->applyToDatabase();

            return;
        }

        // Mode sistem / tanpa pembatasan: pastikan role pemilik dan setting kosong.
        DB::statement('RESET ROLE');
        DB::select('SELECT set_config(?, ?, false)', [Rls::SETTING, '']);
        $this->dbRestricted = false;
        $this->connectionStateUnknown = false;
    }

    private function applyToDatabase(): void
    {
        if (! Rls::isPgsql()) {
            return;
        }

        if ($this->connectionStateUnknown) {
            // Paksa kondisi koneksi sesuai mode, apa pun status sebelumnya.
            DB::statement('RESET ROLE');
            $this->dbRestricted = false;
            $this->connectionStateUnknown = false;
        }

        if ($this->mode === self::MODE_SYSTEM) {
            if ($this->dbRestricted) {
                DB::statement('RESET ROLE');
                $this->dbRestricted = false;
            }
            DB::select('SELECT set_config(?, ?, false)', [Rls::SETTING, '']);

            return;
        }

        if (! $this->dbRestricted) {
            DB::statement('SET ROLE '.Rls::role());
            $this->dbRestricted = true;
        }

        DB::select('SELECT set_config(?, ?, false)', [Rls::SETTING, $this->companyId ?? '']);
    }
}
