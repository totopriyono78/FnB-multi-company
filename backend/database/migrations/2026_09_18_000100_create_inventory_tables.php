<?php

use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Application\RoleProvisioner;
use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inventory, resep & pembelian (FR-INV-01..11, FR-PUR, ADR 0005).
 *
 * Kuantitas memakai NUMERIC(18,4) dalam satuan dasar bahan; harga pokok per satuan dasar NUMERIC(18,6)
 * (Rp per gram/ml terlalu kecil untuk 2 desimal); nilai rupiah tetap NUMERIC(18,2).
 * Kartu stok (stock_movements), dokumen penyesuaian, dan penerimaan barang bersifat append-only.
 */
return new class extends Migration
{
    /** Izin baru di tahap ini beserta role bawaan yang menerimanya. */
    private const NEW_PERMISSIONS = ['inventory.approve_count', 'purchasing.approve'];

    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            // Keputusan user Tahap 4: bawaan stok berkurang saat pesanan dikirim ke dapur.
            $table->string('stock_deduction_trigger', 20)->default('on_kitchen')->change();
        });

        DB::statement('ALTER TABLE orders ADD COLUMN void_stock_action varchar(10) NULL');

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedInteger('last_value')->default(0);
            $table->primary(['company_id', 'key']);
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('category', 40)->nullable();
            $table->string('base_unit', 10)->comment('satuan dasar: g | ml | pcs | lembar | porsi');
            $table->string('kind', 10)->default('raw')->comment('raw = bahan baku | semi = setengah jadi (sub-resep)');
            $table->decimal('min_stock', 18, 4)->default(0);
            $table->decimal('last_cost', 18, 6)->nullable()->comment('Harga beli terakhir per satuan dasar');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();
            $table->index(['company_id', 'category']);
        });
        DB::statement('CREATE UNIQUE INDEX ingredients_code_unique ON ingredients (company_id, lower(code)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE ingredients ADD CONSTRAINT ingredients_kind_check CHECK (kind IN ('raw','semi') AND min_stock >= 0)");

        Schema::create('ingredient_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->string('name', 20);
            $table->decimal('factor', 18, 4)->comment('Jumlah satuan dasar dalam 1 satuan ini');
            $table->boolean('is_purchase_default')->default(false);
            $table->timestampsTz(6);
            $table->index(['company_id', 'ingredient_id']);
        });
        DB::statement('CREATE UNIQUE INDEX ingredient_units_name_unique ON ingredient_units (company_id, ingredient_id, lower(name))');
        DB::statement('ALTER TABLE ingredient_units ADD CONSTRAINT ingredient_units_factor_check CHECK (factor > 0)');

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->string('code', 20);
            $table->string('name', 60);
            $table->uuid('kitchen_station_id')->nullable()->comment('Penjualan dari stasiun ini memotong stok lokasi ini');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);
            $table->unique(['company_id', 'outlet_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX stock_locations_default_unique ON stock_locations (company_id, outlet_id) WHERE is_default');
        DB::statement('CREATE UNIQUE INDEX stock_locations_station_unique ON stock_locations (company_id, outlet_id, kitchen_station_id) WHERE kitchen_station_id IS NOT NULL');

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('target_type', 12)->comment('item | variant | modifier | ingredient');
            $table->uuid('target_id');
            $table->uuid('brand_id')->nullable()->comment('Brand pemilik menu (null untuk sub-resep bahan)');
            $table->decimal('yield_qty', 18, 4)->default(1)->comment('Hasil resep dalam satuan dasar (sub-resep)');
            $table->string('notes', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'target_type', 'target_id']);
            $table->index(['company_id', 'brand_id']);
        });
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_target_check CHECK (target_type IN ('item','variant','modifier','ingredient') AND yield_qty > 0)");

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->decimal('qty', 18, 4)->comment('Satuan dasar; negatif hanya untuk modifier (mis. tanpa gula)');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unique(['recipe_id', 'ingredient_id']);
            $table->index(['company_id', 'ingredient_id']);
        });
        DB::statement('ALTER TABLE recipe_lines ADD CONSTRAINT recipe_lines_qty_check CHECK (qty <> 0)');

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 6)->default(0)->comment('Moving average per satuan dasar');
            $table->decimal('min_qty', 18, 4)->nullable()->comment('Batas minimum lokasi; null = ikut bahan');
            $table->timestampTz('last_movement_at')->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'location_id', 'ingredient_id']);
            $table->index(['company_id', 'ingredient_id']);
        });
        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_cost_check CHECK (avg_cost >= 0 AND (min_qty IS NULL OR min_qty >= 0))');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->string('type', 20);
            $table->decimal('qty', 18, 4)->comment('Positif = masuk, negatif = keluar (satuan dasar)');
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('value', 18, 2)->comment('qty × unit_cost, bertanda');
            $table->decimal('balance_after', 18, 4);
            $table->decimal('avg_cost_after', 18, 6);
            $table->string('reference_type', 30);
            $table->uuid('reference_id');
            $table->string('reference_no', 40)->nullable();
            $table->string('source_key', 160)->nullable()->comment('Kunci idempoten posting otomatis');
            $table->string('reason', 200)->nullable();
            $table->date('business_date');
            $table->timestampTz('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'location_id', 'ingredient_id', 'occurred_at']);
            $table->index(['company_id', 'outlet_id', 'business_date', 'type']);
            $table->index(['company_id', 'reference_type', 'reference_id']);
        });
        DB::statement('CREATE UNIQUE INDEX stock_movements_source_unique ON stock_movements (company_id, source_key) WHERE source_key IS NOT NULL');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('receipt','transfer_out','transfer_in','adjustment','waste','count','sale','sale_return') AND qty <> 0 AND unit_cost >= 0)");

        // Baris penjualan yang stoknya sudah dipotong (dari tiket dapur atau saat bayar) & bahan yang dipakai.
        Schema::create('stock_line_postings', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('line_id');
            $table->uuid('order_id');
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->string('source', 10)->comment('kitchen | order');
            $table->string('type', 20)->comment('sale | waste');
            $table->decimal('qty', 18, 3);
            $table->decimal('returned_qty', 18, 3)->default(0);
            $table->jsonb('consumption')->comment('[{ingredient_id, qty, unit_cost}] per 1 qty baris × qty');
            $table->date('business_date');
            $table->timestampTz('posted_at');
            $table->primary(['company_id', 'line_id']);
            $table->index(['company_id', 'order_id']);
        });
        DB::statement('ALTER TABLE stock_line_postings ADD CONSTRAINT stock_line_postings_qty_check CHECK (qty > 0 AND returned_qty >= 0 AND returned_qty <= qty)');

        // Status pemrosesan event penjualan → stok (untuk pengulangan otomatis bila gagal).
        Schema::create('stock_event_postings', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('key', 80)->comment('completed:{order} | voided:{order} | refund:{refund} | kitchen:{ticket}');
            $table->string('event', 20);
            $table->uuid('subject_id');
            $table->uuid('order_id');
            $table->string('status', 10)->comment('pending | posted | failed');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestampsTz(6);
            $table->primary(['company_id', 'key']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID v7 dari perangkat');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->uuid('shift_id')->nullable();
            $table->uuid('order_id')->comment('ID pesanan di perangkat (boleh belum lunas)');
            $table->uuid('sent_by');
            $table->date('business_date');
            $table->timestampTz('sent_at');
            $table->jsonb('lines');
            $table->timestampTz('server_received_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'outlet_id', 'business_date']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->string('type', 12)->comment('adjustment | waste');
            $table->string('reason_code', 30);
            $table->string('notes', 300)->nullable();
            $table->decimal('total_value', 18, 2);
            $table->date('business_date');
            $table->timestampTz('occurred_at');
            $table->uuid('created_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'outlet_id', 'business_date']);
        });
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_type_check CHECK (type IN ('adjustment','waste'))");

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->decimal('qty', 18, 4)->comment('Bertanda, satuan dasar');
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('value', 18, 2);
            $table->string('note', 200)->nullable();
            $table->index(['company_id', 'stock_adjustment_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('from_outlet_id')->constrained('outlets');
            $table->foreignUuid('from_location_id')->constrained('stock_locations');
            $table->foreignUuid('to_outlet_id')->constrained('outlets');
            $table->foreignUuid('to_location_id')->constrained('stock_locations');
            $table->string('status', 12)->default('in_transit');
            $table->string('notes', 300)->nullable();
            $table->decimal('total_value', 18, 2);
            $table->uuid('sent_by');
            $table->timestampTz('sent_at');
            $table->uuid('received_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->string('receive_note', 300)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_check CHECK (status IN ('in_transit','received','cancelled') AND from_location_id <> to_location_id)");

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('qty_received', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 6);
            $table->string('note', 200)->nullable();
            $table->unique(['stock_transfer_id', 'ingredient_id']);
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_qty_check CHECK (qty_sent > 0 AND (qty_received IS NULL OR qty_received >= 0))');

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->string('scope', 10)->comment('full | partial');
            $table->string('status', 12)->default('counting');
            $table->string('notes', 300)->nullable();
            $table->timestampTz('started_at');
            $table->uuid('started_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->decimal('variance_value', 18, 2)->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'outlet_id', 'status']);
        });
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_check CHECK (scope IN ('full','partial') AND status IN ('counting','submitted','approved','rejected','cancelled'))");
        DB::statement("CREATE UNIQUE INDEX stock_counts_active_unique ON stock_counts (company_id, location_id) WHERE status IN ('counting','submitted')");

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->decimal('system_qty', 18, 4)->comment('Saldo teoritis dibekukan saat opname dimulai');
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('difference', 18, 4)->nullable();
            $table->decimal('variance_value', 18, 2)->nullable();
            $table->string('note', 200)->nullable();
            $table->unique(['stock_count_id', 'ingredient_id']);
        });
        DB::statement('ALTER TABLE stock_count_lines ADD CONSTRAINT stock_count_lines_qty_check CHECK (counted_qty IS NULL OR counted_qty >= 0)');

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('contact_name', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 300)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->string('notes', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz(6);
            $table->softDeletesTz();
        });
        DB::statement('CREATE UNIQUE INDEX suppliers_code_unique ON suppliers (company_id, lower(code)) WHERE deleted_at IS NULL');

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->string('status', 20)->default('draft');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->uuid('created_by');
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz(6);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'outlet_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
        });
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_check CHECK (status IN ('draft','submitted','approved','rejected','partially_received','received','closed','cancelled') AND total >= 0)");

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->string('unit_name', 20);
            $table->decimal('unit_factor', 18, 4);
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('received_qty', 18, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->index(['company_id', 'purchase_order_id']);
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_check CHECK (qty > 0 AND unit_factor > 0 AND unit_price >= 0 AND received_qty >= 0 AND received_qty <= qty)');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('location_id')->constrained('stock_locations');
            $table->uuid('supplier_id')->nullable();
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->uuid('purchase_order_id')->nullable();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->string('notes', 300)->nullable();
            $table->decimal('total', 18, 2);
            $table->date('business_date');
            $table->timestampTz('received_at');
            $table->uuid('received_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'outlet_id', 'business_date']);
            $table->index(['company_id', 'purchase_order_id']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->uuid('purchase_order_line_id')->nullable();
            $table->foreignUuid('ingredient_id')->constrained('ingredients');
            $table->string('unit_name', 20);
            $table->decimal('unit_factor', 18, 4);
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('base_unit_cost', 18, 6);
            $table->index(['company_id', 'goods_receipt_id']);
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_check CHECK (qty > 0 AND unit_factor > 0 AND unit_price >= 0)');

        $tables = ['document_sequences', 'ingredients', 'ingredient_units', 'stock_locations', 'recipes', 'recipe_lines',
            'stock_balances', 'stock_movements', 'stock_line_postings', 'stock_event_postings', 'kitchen_tickets',
            'stock_adjustments', 'stock_adjustment_lines', 'stock_transfers', 'stock_transfer_lines', 'stock_counts',
            'stock_count_lines', 'suppliers', 'purchase_orders', 'purchase_order_lines', 'goods_receipts', 'goods_receipt_lines'];
        foreach ($tables as $t) {
            Rls::enable($t);
        }

        // Append-only: kartu stok, penyesuaian, penerimaan barang, tiket dapur.
        if (Rls::isPgsql()) {
            $role = Rls::role();
            $appendOnly = ['stock_movements', 'stock_adjustments', 'stock_adjustment_lines', 'goods_receipts', 'goods_receipt_lines', 'kitchen_tickets'];
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION inventory_block_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Data % bersifat append-only; koreksi lewat dokumen penyesuaian', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            foreach ($appendOnly as $t) {
                DB::statement("REVOKE UPDATE, DELETE ON {$t} FROM {$role}");
                DB::statement("CREATE TRIGGER {$t}_append_only BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION inventory_block_mutation()");
            }
            foreach (['stock_balances', 'stock_line_postings', 'stock_transfer_lines', 'stock_count_lines', 'stock_transfers', 'stock_counts', 'purchase_orders'] as $t) {
                DB::statement("REVOKE DELETE ON {$t} FROM {$role}");
            }

            // Dokumen yang sudah final tidak dapat diubah lagi.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION inventory_guard_final() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status IN ('received','cancelled','approved','rejected','closed') AND TG_TABLE_NAME <> 'purchase_orders' THEN
                        RAISE EXCEPTION 'Dokumen % yang sudah final tidak dapat diubah', TG_TABLE_NAME;
                    END IF;
                    IF TG_TABLE_NAME = 'purchase_orders' AND OLD.status IN ('received','cancelled','rejected','closed') THEN
                        RAISE EXCEPTION 'Purchase order yang sudah final tidak dapat diubah';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            foreach (['stock_transfers', 'stock_counts', 'purchase_orders'] as $t) {
                DB::statement("CREATE TRIGGER {$t}_guard_final BEFORE UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION inventory_guard_final()");
            }
        }

        $this->backfill();
    }

    /** Lokasi stok bawaan untuk outlet yang sudah ada & izin baru untuk role bawaan. */
    private function backfill(): void
    {
        $now = now();
        foreach (DB::table('outlets')->whereNull('deleted_at')->get(['id', 'company_id']) as $outlet) {
            DB::table('stock_locations')->insert([
                'id' => (string) Str::uuid7(),
                'company_id' => $outlet->company_id,
                'outlet_id' => $outlet->id,
                'code' => 'UTAMA',
                'name' => 'Gudang Utama',
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (DB::table('companies')->doesntExist()) {
            return;
        }
        app(RoleProvisioner::class)->syncPermissions();
        $defaults = PermissionRegistry::defaultRoles();
        $permissionIds = DB::table('permissions')->whereIn('name', self::NEW_PERMISSIONS)->where('guard_name', 'web')->pluck('id', 'name');
        foreach (DB::table('roles')->where('is_system', true)->get(['id', 'name']) as $role) {
            foreach (array_intersect(self::NEW_PERMISSIONS, $defaults[$role->name]['permissions'] ?? []) as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$permission],
                    'role_id' => $role->id,
                ]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders', 'suppliers',
            'stock_count_lines', 'stock_counts', 'stock_transfer_lines', 'stock_transfers', 'stock_adjustment_lines',
            'stock_adjustments', 'kitchen_tickets', 'stock_event_postings', 'stock_line_postings', 'stock_movements',
            'stock_balances', 'recipe_lines', 'recipes', 'stock_locations', 'ingredient_units', 'ingredients', 'document_sequences'] as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        DB::statement('DROP FUNCTION IF EXISTS inventory_block_mutation()');
        DB::statement('DROP FUNCTION IF EXISTS inventory_guard_final()');
        DB::statement('ALTER TABLE orders DROP COLUMN IF EXISTS void_stock_action');
        DB::table('permissions')->whereIn('name', self::NEW_PERMISSIONS)->delete();
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('stock_deduction_trigger', 20)->default('on_payment')->change();
        });
    }
};
