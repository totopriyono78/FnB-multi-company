<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Promo (FR-MENU-11, FR-MENU-12, FR-MENU-13, BR-18). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained('brands');
            $table->string('name', 80);
            $table->string('code', 30)->nullable()->comment('Kode untuk promo manual');
            $table->string('code_hash', 100)->nullable()->comment('PBKDF2 kode untuk validasi offline di POS (ADR 0003)');
            $table->string('type', 20)->comment('percent | amount | buy_x_get_y | special_price');
            $table->string('scope', 10)->default('order')->comment('order | items');
            $table->decimal('value', 18, 2)->default(0);
            $table->decimal('min_purchase', 18, 2)->nullable();
            $table->decimal('max_discount', 18, 2)->nullable();
            $table->unsignedSmallInteger('buy_qty')->nullable();
            $table->unsignedSmallInteger('get_qty')->nullable();
            $table->jsonb('channel_codes')->nullable();
            $table->jsonb('payment_methods')->nullable();
            $table->jsonb('days_of_week')->nullable()->comment('1 = Senin ... 7 = Minggu');
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('stackable')->default(false);
            $table->boolean('auto_apply')->default(true);
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();
            $table->index(['company_id', 'is_active', 'starts_at']);
        });

        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('target_type', 10)->comment('item | category');
            $table->uuid('target_id');
            $table->timestampsTz(6);
            $table->unique(['company_id', 'promotion_id', 'target_type', 'target_id']);
        });

        Schema::create('promotion_outlets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'promotion_id', 'outlet_id']);
        });

        foreach (['promotions', 'promotion_targets', 'promotion_outlets'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_outlets');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
