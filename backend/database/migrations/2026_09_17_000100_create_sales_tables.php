<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi POS (FR-POS, FR-PAY, ADR 0004). Transaksi append-only; orders/order_items/payments dipartisi
 * per bulan menurut business_date (SRS §6.3 butir 6 & 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Waktu server saat perangkat terakhir menarik data master: acuan sah/tidaknya perbedaan harga & pajak.
        Schema::table('devices', function (Blueprint $table) {
            $table->timestampTz('master_pulled_at')->nullable()->after('last_synced_at');
            $table->unsignedBigInteger('master_version')->nullable()->after('master_pulled_at');
        });

        Schema::create('business_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->date('business_date');
            $table->string('status', 10)->default('closed');
            $table->timestampTz('closed_at');
            $table->uuid('closed_by')->nullable();
            $table->jsonb('summary');
            $table->timestampsTz(6);
            $table->unique(['company_id', 'outlet_id', 'business_date']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('UUID v7 dari perangkat');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->uuid('cashier_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 18, 2);
            $table->timestampTz('opened_at');
            $table->string('status', 10)->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->decimal('expected_cash', 18, 2)->nullable();
            $table->decimal('counted_cash', 18, 2)->nullable();
            $table->decimal('cash_variance', 18, 2)->nullable();
            $table->jsonb('denominations')->nullable();
            $table->text('variance_note')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestampTz('master_pulled_at')->nullable()->comment('Tarik data master terakhir perangkat saat shift dibuka');
            $table->timestampTz('server_received_at');
            $table->timestampsTz(6);
            $table->index(['company_id', 'outlet_id', 'business_date']);
            $table->index(['company_id', 'device_id', 'status']);
        });
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('open','closed'))");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts');
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->date('business_date');
            $table->string('type', 20)->comment('in | out | drawer_open');
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('reason', 200);
            $table->uuid('created_by');
            $table->uuid('authorized_by')->nullable();
            $table->timestampTz('device_created_at');
            $table->timestampTz('server_received_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'shift_id']);
        });
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_type_check CHECK (type IN ('in','out','drawer_open') AND amount >= 0)");

        DB::statement(<<<'SQL'
            CREATE TABLE orders (
                id uuid NOT NULL,
                business_date date NOT NULL,
                company_id uuid NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                outlet_id uuid NOT NULL REFERENCES outlets(id),
                device_id uuid NOT NULL REFERENCES devices(id),
                shift_id uuid NOT NULL REFERENCES shifts(id),
                cashier_id uuid NOT NULL,
                receipt_no varchar(40) NOT NULL,
                queue_no integer NULL,
                sales_channel_id uuid NULL,
                channel_code varchar(30) NOT NULL,
                table_label varchar(30) NULL,
                customer_name varchar(80) NULL,
                note varchar(300) NULL,
                status varchar(20) NOT NULL,
                subtotal numeric(18,2) NOT NULL,
                item_discount numeric(18,2) NOT NULL,
                order_discount numeric(18,2) NOT NULL,
                service_charge numeric(18,2) NOT NULL,
                tax numeric(18,2) NOT NULL,
                rounding numeric(18,2) NOT NULL,
                total numeric(18,2) NOT NULL,
                paid_total numeric(18,2) NOT NULL DEFAULT 0,
                change_amount numeric(18,2) NOT NULL DEFAULT 0,
                refunded_total numeric(18,2) NOT NULL DEFAULT 0,
                tax_name varchar(20) NOT NULL,
                pricing jsonb NOT NULL,
                totals jsonb NOT NULL,
                promo_codes jsonb NULL,
                flags jsonb NOT NULL DEFAULT '[]'::jsonb,
                device_created_at timestamptz NOT NULL,
                completed_at timestamptz NULL,
                server_received_at timestamptz NOT NULL,
                voided_at timestamptz NULL,
                voided_by uuid NULL,
                void_authorized_by uuid NULL,
                void_reason varchar(300) NULL,
                void_business_date date NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, business_date),
                CONSTRAINT orders_status_check CHECK (status IN ('paid','voided','refunded','partially_refunded')),
                CONSTRAINT orders_money_check CHECK (total >= 0 AND paid_total >= 0 AND change_amount >= 0 AND refunded_total >= 0 AND refunded_total <= total)
            ) PARTITION BY RANGE (business_date)
        SQL);
        DB::statement('CREATE UNIQUE INDEX orders_receipt_unique ON orders (company_id, receipt_no, business_date)');
        DB::statement('CREATE INDEX orders_company_outlet_date_idx ON orders (company_id, outlet_id, business_date)');
        DB::statement('CREATE INDEX orders_company_shift_idx ON orders (company_id, shift_id)');
        DB::statement('CREATE INDEX orders_company_cashier_idx ON orders (company_id, cashier_id, business_date)');

        DB::statement(<<<'SQL'
            CREATE TABLE order_items (
                id uuid NOT NULL,
                business_date date NOT NULL,
                order_id uuid NOT NULL,
                company_id uuid NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                line_no smallint NOT NULL,
                item_id uuid NOT NULL,
                item_variant_id uuid NULL,
                item_type varchar(10) NOT NULL,
                sku varchar(40) NOT NULL,
                name varchar(100) NOT NULL,
                variant_name varchar(40) NULL,
                category_id uuid NULL,
                kitchen_station_id uuid NULL,
                qty numeric(18,3) NOT NULL,
                unit_price numeric(18,2) NOT NULL,
                catalog_price numeric(18,2) NULL,
                modifiers jsonb NOT NULL DEFAULT '[]'::jsonb,
                bundle jsonb NOT NULL DEFAULT '[]'::jsonb,
                gross numeric(18,2) NOT NULL,
                item_discount numeric(18,2) NOT NULL,
                order_discount numeric(18,2) NOT NULL,
                net numeric(18,2) NOT NULL,
                status varchar(10) NOT NULL DEFAULT 'sold',
                void_reason varchar(300) NULL,
                voided_by uuid NULL,
                void_authorized_by uuid NULL,
                sent_to_kitchen_at timestamptz NULL,
                note varchar(200) NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, business_date),
                FOREIGN KEY (order_id, business_date) REFERENCES orders (id, business_date) ON DELETE CASCADE,
                CONSTRAINT order_items_status_check CHECK (status IN ('sold','voided')),
                CONSTRAINT order_items_qty_check CHECK (qty > 0)
            ) PARTITION BY RANGE (business_date)
        SQL);
        DB::statement('CREATE INDEX order_items_order_idx ON order_items (company_id, order_id)');
        DB::statement('CREATE INDEX order_items_item_idx ON order_items (company_id, item_id, business_date)');

        DB::statement(<<<'SQL'
            CREATE TABLE payments (
                id uuid NOT NULL,
                business_date date NOT NULL,
                order_id uuid NOT NULL,
                company_id uuid NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                outlet_id uuid NOT NULL,
                shift_id uuid NOT NULL,
                method varchar(20) NOT NULL,
                amount numeric(18,2) NOT NULL,
                tendered numeric(18,2) NULL,
                change_amount numeric(18,2) NOT NULL DEFAULT 0,
                reference varchar(60) NULL,
                payment_intent_id uuid NULL,
                mdr_amount numeric(18,2) NOT NULL DEFAULT 0,
                device_created_at timestamptz NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, business_date),
                FOREIGN KEY (order_id, business_date) REFERENCES orders (id, business_date) ON DELETE CASCADE,
                CONSTRAINT payments_amount_check CHECK (amount > 0 AND change_amount >= 0)
            ) PARTITION BY RANGE (business_date)
        SQL);
        DB::statement('CREATE INDEX payments_order_idx ON payments (company_id, order_id)');
        DB::statement('CREATE INDEX payments_shift_idx ON payments (company_id, shift_id, method)');
        DB::statement('CREATE UNIQUE INDEX payments_intent_unique ON payments (payment_intent_id, business_date) WHERE payment_intent_id IS NOT NULL');

        foreach (['orders', 'order_items', 'payments'] as $partitioned) {
            DB::statement("CREATE TABLE {$partitioned}_default PARTITION OF {$partitioned} DEFAULT");
        }

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('order_id');
            $table->date('business_date');
            $table->uuid('order_item_id')->nullable();
            $table->string('source', 20)->comment('manual | promo');
            $table->uuid('promotion_id')->nullable();
            $table->string('type', 10)->comment('percent | amount');
            $table->decimal('value', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->uuid('cashier_id');
            $table->uuid('authorized_by')->nullable();
            $table->string('reason', 200)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'business_date', 'source']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->uuid('order_id');
            $table->date('order_business_date');
            $table->date('business_date')->comment('Hari bisnis saat refund dicatat (BR-13)');
            $table->uuid('shift_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20);
            $table->jsonb('lines')->comment('[{order_item_id, qty, amount}] kosong = seluruh transaksi');
            $table->string('stock_action', 10)->comment('return | waste');
            $table->string('reason', 300);
            $table->uuid('refunded_by');
            $table->uuid('authorized_by')->nullable();
            $table->jsonb('flags')->default('[]');
            $table->timestampTz('device_created_at');
            $table->timestampTz('server_received_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'order_id']);
            $table->index(['company_id', 'outlet_id', 'business_date']);
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');

        Schema::create('outlet_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('method', 20);
            $table->string('label', 40);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('mdr_percent', 5, 2)->default(0);
            $table->decimal('mdr_fixed', 18, 2)->default(0);
            $table->timestampsTz(6);
            $table->unique(['company_id', 'outlet_id', 'method']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->foreignUuid('device_id')->constrained('devices');
            $table->uuid('order_ref')->comment('ID order dari perangkat');
            $table->string('method', 20);
            $table->string('provider', 30);
            $table->decimal('amount', 18, 2);
            $table->string('status', 15)->default('pending');
            $table->string('provider_reference', 100)->nullable();
            $table->text('qr_string')->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('consumed_by_order_id')->nullable();
            $table->uuid('created_by');
            $table->jsonb('provider_payload')->nullable();
            $table->timestampsTz(6);
            $table->unique(['provider', 'provider_reference']);
            $table->index(['company_id', 'device_id', 'status']);
        });
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_status_check CHECK (status IN ('pending','paid','expired','cancelled','failed','paid_late') AND amount > 0)");

        // Diterima sebelum company diketahui → ditulis & dibaca lewat mode sistem. RLS tetap aktif sehingga role
        // aplikasi dalam konteks tenant hanya melihat event milik company-nya (setelah diproses).
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 30);
            $table->string('event_id', 120);
            $table->boolean('signature_valid');
            $table->uuid('company_id')->nullable();
            $table->jsonb('payload');
            $table->string('result', 30)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('sync_versions', function (Blueprint $table) {
            $table->foreignUuid('company_id')->primary()->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('updated_at');
        });

        Schema::create('sync_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('idempotency key batch dari perangkat');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('devices');
            $table->unsignedSmallInteger('entity_count');
            $table->unsignedSmallInteger('accepted_count')->default(0);
            $table->unsignedSmallInteger('duplicate_count')->default(0);
            $table->unsignedSmallInteger('rejected_count')->default(0);
            $table->integer('clock_offset_seconds')->nullable();
            $table->timestampTz('received_at');
            $table->index(['company_id', 'device_id', 'received_at']);
        });

        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('device_id')->constrained('devices');
            $table->uuid('batch_id')->nullable();
            $table->string('entity_type', 30);
            $table->uuid('entity_id');
            $table->char('payload_hash', 64);
            $table->jsonb('result');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'entity_type', 'entity_id']);
        });

        foreach (['business_days', 'shifts', 'cash_movements', 'orders', 'order_items', 'payments', 'order_discounts',
            'refunds', 'outlet_payment_methods', 'payment_intents', 'webhook_events', 'sync_versions', 'sync_batches', 'sync_receipts'] as $t) {
            Rls::enable($t);
        }

        // Append-only: tidak ada DELETE untuk role aplikasi; UPDATE hanya kolom status yang diizinkan.
        $role = Rls::role();
        foreach (['cash_movements', 'order_discounts', 'refunds', 'order_items', 'payments', 'orders', 'sync_receipts', 'business_days'] as $t) {
            DB::statement("REVOKE DELETE ON {$t} FROM {$role}");
        }
        foreach (['cash_movements', 'order_discounts', 'refunds', 'payments', 'sync_receipts'] as $t) {
            DB::statement("REVOKE UPDATE ON {$t} FROM {$role}");
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION sales_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Transaksi % bersifat append-only; gunakan void/refund', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        foreach (['cash_movements', 'order_discounts', 'refunds', 'payments'] as $t) {
            DB::statement("CREATE TRIGGER {$t}_append_only BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION sales_block_mutation()");
        }

        // orders: hanya kolom status/void/refund yang boleh berubah; kolom keuangan & identitas terkunci.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION orders_guard_update() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Transaksi orders bersifat append-only';
                END IF;
                IF (NEW.id, NEW.business_date, NEW.company_id, NEW.outlet_id, NEW.device_id, NEW.shift_id, NEW.cashier_id,
                    NEW.receipt_no, NEW.channel_code, NEW.subtotal, NEW.item_discount, NEW.order_discount,
                    NEW.service_charge, NEW.tax, NEW.rounding, NEW.total, NEW.paid_total, NEW.change_amount,
                    NEW.pricing, NEW.totals, NEW.device_created_at, NEW.server_received_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.business_date, OLD.company_id, OLD.outlet_id, OLD.device_id, OLD.shift_id, OLD.cashier_id,
                    OLD.receipt_no, OLD.channel_code, OLD.subtotal, OLD.item_discount, OLD.order_discount,
                    OLD.service_charge, OLD.tax, OLD.rounding, OLD.total, OLD.paid_total, OLD.change_amount,
                    OLD.pricing, OLD.totals, OLD.device_created_at, OLD.server_received_at) THEN
                    RAISE EXCEPTION 'Data keuangan transaksi tidak dapat diubah; gunakan void/refund';
                END IF;
                IF OLD.status = 'voided' THEN
                    RAISE EXCEPTION 'Transaksi yang sudah di-void tidak dapat diubah';
                END IF;
                IF NEW.refunded_total < OLD.refunded_total THEN
                    RAISE EXCEPTION 'Nilai refund tidak dapat berkurang';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER orders_guard BEFORE UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION orders_guard_update()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_items_guard_update() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Transaksi order_items bersifat append-only';
                END IF;
                IF (NEW.id, NEW.order_id, NEW.qty, NEW.unit_price, NEW.gross, NEW.item_discount, NEW.order_discount, NEW.net, NEW.item_id)
                   IS DISTINCT FROM (OLD.id, OLD.order_id, OLD.qty, OLD.unit_price, OLD.gross, OLD.item_discount, OLD.order_discount, OLD.net, OLD.item_id)
                   OR OLD.status = 'voided' THEN
                    RAISE EXCEPTION 'Baris transaksi tidak dapat diubah; gunakan void/refund';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER order_items_guard BEFORE UPDATE OR DELETE ON order_items FOR EACH ROW EXECUTE FUNCTION order_items_guard_update()');

        // Shift yang sudah ditutup tidak dapat diubah.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION shifts_guard_update() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Shift tidak dapat dihapus';
                END IF;
                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'Shift yang sudah ditutup tidak dapat diubah';
                END IF;
                IF (NEW.id, NEW.outlet_id, NEW.device_id, NEW.cashier_id, NEW.business_date, NEW.opening_cash, NEW.opened_at)
                   IS DISTINCT FROM (OLD.id, OLD.outlet_id, OLD.device_id, OLD.cashier_id, OLD.business_date, OLD.opening_cash, OLD.opened_at) THEN
                    RAISE EXCEPTION 'Data pembukaan shift tidak dapat diubah';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER shifts_guard BEFORE UPDATE OR DELETE ON shifts FOR EACH ROW EXECUTE FUNCTION shifts_guard_update()');

        Artisan::call('fnb:partitions', ['--months' => 3]);
    }

    public function down(): void
    {
        foreach (['sync_receipts', 'sync_batches', 'sync_versions', 'webhook_events', 'payment_intents', 'outlet_payment_methods',
            'refunds', 'order_discounts', 'payments', 'order_items', 'orders', 'cash_movements', 'shifts', 'business_days'] as $t) {
            DB::statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        foreach (['sales_block_mutation', 'orders_guard_update', 'order_items_guard_update', 'shifts_guard_update'] as $fn) {
            DB::statement("DROP FUNCTION IF EXISTS {$fn}()");
        }
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['master_pulled_at', 'master_version']);
        });
    }
};
