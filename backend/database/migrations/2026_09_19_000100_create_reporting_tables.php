<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan & dashboard (FR-RPT-01..08, ADR 0006): jadwal laporan email, riwayat pengiriman, dan indeks
 * pendukung query laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('report_key', 40);
            $table->string('format', 10)->comment('xlsx | pdf');
            $table->string('frequency', 10)->comment('daily | weekly | monthly');
            $table->time('send_time')->default('07:00');
            $table->uuid('brand_id')->nullable();
            $table->uuid('outlet_id')->nullable();
            $table->jsonb('recipients');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->comment('Pemilik jadwal; laporan dibangun dengan hak aksesnya');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('next_run_at', 6)->nullable();
            $table->timestampTz('last_run_at', 6)->nullable();
            $table->string('last_status', 10)->nullable();
            $table->string('disabled_reason', 200)->nullable();
            $table->timestampsTz(6);
            $table->index(['company_id', 'created_by']);
            $table->index(['is_active', 'next_run_at']);
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_values_check CHECK (
            format IN ('xlsx','pdf') AND frequency IN ('daily','weekly','monthly') AND jsonb_typeof(recipients) = 'array')");

        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->string('report_key', 40);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 10)->comment('sent | failed | skipped');
            $table->jsonb('recipients');
            $table->string('filename', 150)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->index(['company_id', 'schedule_id', 'created_at']);
        });
        // Satu pengiriman berhasil per jadwal-periode (idempoten saat perintah berjalan bersamaan/diulang).
        DB::statement("CREATE UNIQUE INDEX report_deliveries_once ON report_deliveries (schedule_id, period_from, period_to) WHERE status = 'sent'");

        foreach (['report_schedules', 'report_deliveries'] as $t) {
            Rls::enable($t);
        }

        if (Rls::isPgsql()) {
            $role = Rls::role();
            // Riwayat pengiriman append-only.
            DB::statement("REVOKE UPDATE, DELETE ON report_deliveries FROM {$role}");
        }

        // Indeks pendukung laporan (query per outlet & hari bisnis).
        DB::statement('CREATE INDEX IF NOT EXISTS payments_outlet_date_idx ON payments (company_id, outlet_id, business_date)');
        DB::statement('CREATE INDEX IF NOT EXISTS cash_movements_outlet_date_idx ON cash_movements (company_id, outlet_id, business_date, type)');
        DB::statement('CREATE INDEX IF NOT EXISTS stock_line_postings_outlet_date_idx ON stock_line_postings (company_id, outlet_id, business_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_outlet_date_idx');
        DB::statement('DROP INDEX IF EXISTS cash_movements_outlet_date_idx');
        DB::statement('DROP INDEX IF EXISTS stock_line_postings_outlet_date_idx');
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('report_schedules');
    }
};
