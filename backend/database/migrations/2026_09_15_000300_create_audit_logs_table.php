<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Audit log append-only (FR-AUD-01) dan dipartisi per bulan (SRS §6.3 butir 9).
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id uuid NOT NULL,
                company_id uuid NULL,
                user_id uuid NULL,
                device_id uuid NULL,
                authorized_by uuid NULL,
                action varchar(80) NOT NULL,
                auditable_type varchar(80) NULL,
                auditable_id uuid NULL,
                old_values jsonb NULL,
                new_values jsonb NULL,
                reason text NULL,
                metadata jsonb NULL,
                ip_address varchar(45) NULL,
                user_agent text NULL,
                request_id varchar(40) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        SQL);

        DB::statement('CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT');
        DB::statement('CREATE INDEX audit_logs_company_created_idx ON audit_logs (company_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_company_entity_idx ON audit_logs (company_id, auditable_type, auditable_id)');
        DB::statement('CREATE INDEX audit_logs_company_user_idx ON audit_logs (company_id, user_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_company_action_idx ON audit_logs (company_id, action)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs bersifat append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER audit_logs_no_update BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation()');

        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        $expr = Rls::currentCompanyExpression();
        // Tenant hanya melihat log miliknya; log platform (company_id NULL) hanya bisa ditulis oleh role pemilik.
        DB::statement("CREATE POLICY tenant_isolation ON audit_logs USING (company_id = {$expr}) WITH CHECK (company_id = {$expr})");
        $role = Rls::role();
        DB::statement("GRANT SELECT, INSERT ON audit_logs TO {$role}");

        Artisan::call('fnb:partitions', ['--months' => 3]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS audit_logs_block_mutation()');
    }
};
