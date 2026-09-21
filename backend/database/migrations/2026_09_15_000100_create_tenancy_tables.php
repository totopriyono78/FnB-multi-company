<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paket langganan bersifat global milik platform (FR-TEN-06).
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('max_outlets')->nullable()->comment('null = tanpa batas');
            $table->unsignedInteger('max_devices')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->jsonb('modules')->default('[]');
            $table->decimal('price_per_outlet_month', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        Rls::grant('plans');

        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('legal_name', 150)->nullable();
            $table->string('npwp', 25)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->char('currency', 3)->default('IDR');
            $table->string('logo_path')->nullable();
            $table->string('status', 20)->default('trial')->index();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans');
            $table->timestampTz('subscription_ends_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->boolean('allow_support_access')->default(false);
            $table->jsonb('settings')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        // Untuk tabel companies, kunci tenant adalah kolom id itu sendiri.
        Rls::enable('companies', 'id');

        Schema::create('company_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module', 20);
            $table->boolean('is_enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'module']);
        });
        Rls::enable('company_modules');

        Schema::create('brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
        Rls::enable('brands');

        Schema::create('outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->string('code', 10)->comment('Dipakai di nomor struk');
            $table->string('name', 120);
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->jsonb('opening_hours')->default('{}');
            $table->time('business_day_cutoff')->default('04:00')->comment('BR-20');
            // Pajak & service charge dapat dikonfigurasi (NFR-CMP-03); default 0 sampai diisi tenant.
            $table->string('tax_name', 20)->default('PB1');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->boolean('tax_on_service_charge')->default(true);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->unsignedInteger('rounding_unit')->default(0)->comment('0 = tanpa pembulatan');
            $table->string('rounding_mode', 10)->default('nearest');
            $table->string('order_mode', 20)->default('quick_service');
            $table->string('stock_deduction_trigger', 20)->default('on_payment');
            $table->boolean('allow_negative_stock')->default(true);
            $table->string('npwpd', 30)->nullable();
            $table->jsonb('receipt_settings')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'brand_id']);
        });
        Rls::enable('outlets');

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->string('code', 10);
            $table->string('name', 60);
            $table->string('type', 20)->default('pos');
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('paired_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->unsignedInteger('pending_sync_count')->default(0);
            $table->timestampTz('wipe_requested_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'outlet_id', 'code']);
            $table->index(['company_id', 'status']);
        });
        Rls::enable('devices');

        Schema::create('device_pairing_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'device_id']);
        });
        Rls::enable('device_pairing_codes');
    }

    public function down(): void
    {
        foreach (['device_pairing_codes', 'devices', 'outlets', 'brands', 'company_modules', 'companies', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
