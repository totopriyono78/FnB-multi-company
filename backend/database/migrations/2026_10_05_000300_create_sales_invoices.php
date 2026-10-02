<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pelanggan & tagihan keluar (Kelompok 5: AR-01, AR-02).
 *
 * Ini **bukan** penjualan kasir. Penjualan POS dibayar di tempat dan sudah punya jalurnya sendiri
 * sampai ke jurnal. Yang ditangani di sini adalah penjualan yang **ditagih**: katering korporat,
 * sewa tempat, kerja sama acara — penjualan yang uangnya datang belakangan, dan karena itu menjadi
 * piutang yang bisa terlupa.
 *
 * Dua keputusan bentuk data:
 *
 * 1. **Pelunasan menunjuk rekening kas/bank, bukan akun buku besar.** Yang menerima uang adalah
 *    rekening yang nyata, dan rekonsiliasi bank nanti mencari pasangannya di rekening itu. Menunjuk
 *    akun saja akan membuat penerimaan dari dua bank berbeda tidak dapat dibedakan lagi.
 * 2. **PPN keluaran disimpan di kepala, sejajar dengan faktur pembelian.** Bentuk yang sama membuat
 *    rekap PPN masukan dan keluaran bisa disusun dengan cara yang sama persis.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- AR-01: pelanggan ---------- */
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('npwp', 25)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
        });
        Rls::enable('customers');

        /* ---------- AR-02: tagihan keluar ---------- */
        Schema::create('sales_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignUuid('customer_id')->constrained('customers');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->uuid('outlet_id')->nullable();
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->boolean('has_tax_invoice')->default(false);
            $table->decimal('tax_amount', 18, 2)->default(0)->comment('PPN keluaran');
            $table->string('tax_invoice_no', 60)->nullable();
            $table->date('tax_invoice_date')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->string('status', 12)->default('draft');
            $table->string('description', 300);
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['company_id', 'customer_id']);
        });
        DB::statement("ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_status_check
            CHECK (status IN ('draft','issued','paid','cancelled'))");
        DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_total_check
            CHECK (total = subtotal + tax_amount)');
        DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_paid_check
            CHECK (paid_amount >= 0 AND paid_amount <= total)');
        DB::statement('ALTER TABLE sales_invoices ADD CONSTRAINT sales_invoices_tax_check CHECK (
            (has_tax_invoice = true AND tax_invoice_no IS NOT NULL)
            OR (has_tax_invoice = false AND tax_amount = 0)
        )');
        Rls::enable('sales_invoices');

        Schema::create('sales_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 200);
            $table->foreignUuid('account_id')
                ->comment('Akun pendapatan yang dikredit')
                ->constrained('accounts');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('amount', 18, 2);
            $table->timestampsTz();

            $table->unique(['sales_invoice_id', 'line_no']);
        });
        DB::statement('ALTER TABLE sales_invoice_lines ADD CONSTRAINT sales_invoice_lines_amount_check CHECK (amount > 0)');
        Rls::enable('sales_invoice_lines');

        /* ---------- AR-02: pelunasan ---------- */
        Schema::create('sales_invoice_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignUuid('sales_invoice_id')->constrained('sales_invoices');
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts');
            $table->date('received_on');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'sales_invoice_id']);
        });
        DB::statement('ALTER TABLE sales_invoice_receipts ADD CONSTRAINT sales_invoice_receipts_amount_check CHECK (amount > 0)');
        Rls::enable('sales_invoice_receipts');
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_receipts');
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('customers');
    }
};
