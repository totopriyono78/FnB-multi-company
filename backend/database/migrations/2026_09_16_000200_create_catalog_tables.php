<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul menu & harga (FR-MENU-01 s.d. FR-MENU-10, FR-MENU-14, FR-MENU-15).
 * Kolom updated_at presisi mikrodetik dipakai untuk sinkronisasi inkremental (SRS §6.3 butir 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('sales_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 60);
            $table->string('type', 20)->comment('in_store | aggregator | online');
            $table->boolean('service_charge_applies')->default(true)->comment('BR-01');
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->unique(['company_id', 'code']);
        });

        Schema::create('menu_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->string('name', 60);
            $table->string('color', 20)->default('gray');
            $table->string('icon', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();
            $table->index(['company_id', 'brand_id', 'sort_order']);
        });
        DB::statement('CREATE UNIQUE INDEX menu_categories_name_unique ON menu_categories (company_id, brand_id, lower(name)) WHERE deleted_at IS NULL');

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->foreignUuid('category_id')->constrained('menu_categories');
            $table->string('type', 10)->default('single')->comment('single | bundle');
            $table->string('sku', 40);
            $table->string('barcode', 40)->nullable();
            $table->string('name', 100);
            $table->string('short_name', 24);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('base_price', 18, 2);
            $table->foreignUuid('kitchen_station_id')->nullable()->constrained('kitchen_stations')->nullOnDelete();
            $table->jsonb('channel_codes')->nullable()->comment('null = semua channel (FR-MENU-07)');
            $table->jsonb('schedule')->nullable()->comment('null = sepanjang jam buka (FR-MENU-07)');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();
            $table->index(['company_id', 'brand_id', 'category_id']);
            $table->index(['company_id', 'updated_at']);
        });
        DB::statement('CREATE UNIQUE INDEX items_sku_unique ON items (company_id, brand_id, lower(sku)) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX items_barcode_idx ON items (company_id, barcode) WHERE barcode IS NOT NULL');

        Schema::create('item_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('sku', 40)->nullable();
            $table->decimal('price', 18, 2)->comment('Harga penuh varian, bukan selisih');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->index(['company_id', 'item_id']);
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->constrained('brands');
            $table->string('name', 60);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->softDeletesTz();
            $table->index(['company_id', 'brand_id']);
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('price', 18, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->index(['company_id', 'modifier_group_id']);
        });

        Schema::create('item_modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);
            $table->unique(['company_id', 'item_id', 'modifier_group_id']);
        });

        // Paket/bundling (FR-MENU-05): grup pilihan dengan opsi yang bisa diganti.
        Schema::create('bundle_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('min_select')->default(1);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);
            $table->index(['company_id', 'item_id']);
        });

        Schema::create('bundle_group_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('bundle_group_id')->constrained('bundle_groups')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items');
            $table->foreignUuid('item_variant_id')->nullable()->constrained('item_variants')->cascadeOnDelete();
            $table->decimal('extra_price', 18, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz(6);
            $table->index(['company_id', 'bundle_group_id']);
        });

        // Harga khusus per outlet dan/atau channel (FR-MENU-06).
        Schema::create('item_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->foreignUuid('item_variant_id')->nullable()->constrained('item_variants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('sales_channel_id')->nullable()->constrained('sales_channels')->cascadeOnDelete();
            $table->decimal('price', 18, 2);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->index(['company_id', 'item_id']);
        });
        DB::statement('CREATE UNIQUE INDEX item_prices_scope_unique ON item_prices (company_id, item_id, item_variant_id, outlet_id, sales_channel_id) NULLS NOT DISTINCT');
        DB::statement('ALTER TABLE item_prices ADD CONSTRAINT item_prices_has_scope CHECK (outlet_id IS NOT NULL OR sales_channel_id IS NOT NULL)');

        // Riwayat perubahan harga, append-only (FR-MENU-15).
        Schema::create('item_price_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('item_id');
            $table->uuid('item_variant_id')->nullable();
            $table->uuid('outlet_id')->nullable();
            $table->uuid('sales_channel_id')->nullable();
            $table->decimal('old_price', 18, 2)->nullable();
            $table->decimal('new_price', 18, 2)->nullable();
            $table->uuid('changed_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'item_id', 'created_at']);
        });

        // Status per outlet: dijual/tidak dan habis (FR-MENU-07, FR-MENU-08).
        Schema::create('outlet_item_availability', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('items')->cascadeOnDelete();
            $table->boolean('is_listed')->default(true);
            $table->boolean('is_sold_out')->default(false);
            $table->timestampTz('sold_out_at')->nullable();
            $table->uuid('sold_out_by')->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'outlet_id', 'item_id']);
        });

        foreach ([
            'kitchen_stations', 'sales_channels', 'menu_categories', 'items', 'item_variants', 'modifier_groups',
            'modifiers', 'item_modifier_groups', 'bundle_groups', 'bundle_group_options', 'item_prices',
            'item_price_histories', 'outlet_item_availability',
        ] as $table) {
            Rls::enable($table);
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION item_price_histories_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'item_price_histories bersifat append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER item_price_histories_no_update BEFORE UPDATE OR DELETE ON item_price_histories FOR EACH ROW EXECUTE FUNCTION item_price_histories_block_mutation()');
        $role = Rls::role();
        DB::statement("REVOKE UPDATE, DELETE ON item_price_histories FROM {$role}");
    }

    public function down(): void
    {
        foreach ([
            'outlet_item_availability', 'item_price_histories', 'item_prices', 'bundle_group_options', 'bundle_groups',
            'item_modifier_groups', 'modifiers', 'modifier_groups', 'item_variants', 'items', 'menu_categories',
            'sales_channels', 'kitchen_stations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS item_price_histories_block_mutation()');
    }
};
