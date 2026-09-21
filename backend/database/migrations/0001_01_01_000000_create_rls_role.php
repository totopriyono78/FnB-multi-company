<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $role = Rls::role();

        DB::statement(<<<SQL
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    CREATE ROLE {$role} NOLOGIN NOBYPASSRLS;
                END IF;
            END
            $$;
        SQL);

        // User koneksi aplikasi harus boleh SET ROLE ke role RLS.
        DB::statement("GRANT {$role} TO CURRENT_USER");
        DB::statement("GRANT USAGE ON SCHEMA public TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
    }

    public function down(): void
    {
        // Role dibiarkan karena bisa dipakai database lain di server yang sama.
    }
};
