<?php

namespace App\Modules\Shared\Infrastructure\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helper migrasi untuk Row-Level Security PostgreSQL (NFR-SEC-01).
 *
 * Kebijakan membaca `app.current_company_id` yang di-set oleh TenantContext.
 * Role aplikasi (config database.connections.pgsql.rls_role) tidak punya BYPASSRLS,
 * sehingga tanpa konteks tenant tabel terlihat kosong (default deny).
 */
final class Rls
{
    public const SETTING = 'app.current_company_id';

    public static function currentCompanyExpression(): string
    {
        return "NULLIF(current_setting('".self::SETTING."', true), '')::uuid";
    }

    public static function enable(string $table, string $column = 'company_id', bool $allowNullShared = false): void
    {
        if (! self::isPgsql()) {
            return;
        }

        $expr = self::currentCompanyExpression();
        $using = $allowNullShared
            ? "({$column} IS NULL OR {$column} = {$expr})"
            : "({$column} = {$expr})";
        $check = "({$column} = {$expr})";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$using} WITH CHECK {$check}");
        self::grant($table);
    }

    public static function grant(string $table): void
    {
        if (! self::isPgsql()) {
            return;
        }

        $role = self::role();
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO {$role}");
    }

    public static function role(): string
    {
        $role = (string) config('database.connections.pgsql.rls_role', 'fnb_app');

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            throw new \InvalidArgumentException('Nama role RLS tidak valid.');
        }

        return $role;
    }

    public static function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
