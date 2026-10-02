<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faktur pembelian & hutang usaha (Kelompok 5: AP-01, AP-02, AP-03, AP-04; TAX-02).
 *
 * Inilah yang membuat beban diakui **saat terjadi**, bukan saat dibayar. Sebelum ini, pengeluaran
 * hanya muncul di buku ketika advis bayar terbit — benar untuk pembayaran langsung, tetapi salah
 * untuk pembelian berjangka, dan di F&B justru itu yang terbanyak.
 *
 * Empat keputusan bentuk data:
 *
 * 1. **Nomor faktur supplier dijaga unik per supplier.** Membayar faktur yang sama dua kali adalah
 *    kerugian klasik yang tidak pernah ketahuan dari buku besar — angkanya tetap seimbang, hanya
 *    uangnya hilang. Indeks parsial (hanya saat nomornya diisi) membuat basis data yang menolaknya,
 *    bukan kewaspadaan orang.
 * 2. **PPN disimpan di kepala faktur, bukan per baris.** Satu faktur = satu perlakuan pajak dalam
 *    praktik F&B; memindahkannya ke baris hanya menambah isian yang selalu sama di tiap baris.
 *    Keputusan user 2 Okt 2026: perlakuannya **campuran** — ada tidaknya faktur pajak ditentukan per
 *    dokumen, dengan nilai bawaan mengikuti status PKP supplier, supaya orang entry tidak menebak.
 * 3. **Faktur boleh lahir dari penerimaan barang atau berdiri sendiri.** Bahan baku datang lewat
 *    penerimaan barang; listrik, sewa, dan jasa tidak pernah punya penerimaan barang. Memaksa
 *    keduanya lewat satu jalan berarti salah satunya akan lari ke jalan yang tidak terkontrol.
 * 4. **Pelunasan dicatat sebagai alokasi, bukan kolom.** Satu pembayaran bisa melunasi beberapa
 *    faktur dan satu faktur bisa dilunasi beberapa kali; hanya tabel alokasi yang bisa menjawab
 *    "faktur ini dibayar lewat advis mana" dan "advis ini melunasi faktur apa saja" sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- AP-01: supplier diperluas ---------- */
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('npwp', 25)->nullable()->after('address');
            $table->boolean('is_pkp')->default(false)->after('npwp')
                ->comment('Pengusaha Kena Pajak: menentukan nilai bawaan "ada faktur pajak" saat faktur dibuat');
            $table->string('bank_name', 100)->nullable()->after('is_pkp');
            $table->string('bank_account_number', 50)->nullable()->after('bank_name');
            $table->string('bank_account_holder', 100)->nullable()->after('bank_account_number');
        });

        /* ---------- AP-02: faktur pembelian ---------- */
        Schema::create('purchase_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 30)->comment('Nomor internal kita');
            $table->foreignUuid('supplier_id')->constrained('suppliers');
            $table->string('supplier_invoice_no', 60)->nullable()->comment('Nomor faktur dari supplier');
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->uuid('goods_receipt_id')->nullable()->comment('Bila faktur ditarik dari penerimaan barang');
            $table->foreign('goods_receipt_id')->references('id')->on('goods_receipts');
            $table->uuid('outlet_id')->nullable();
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->decimal('subtotal', 18, 2)->default(0)->comment('DPP: jumlah seluruh baris');
            $table->boolean('has_tax_invoice')->default(false);
            $table->decimal('tax_amount', 18, 2)->default(0)->comment('PPN masukan');
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
            $table->index(['company_id', 'supplier_id']);
        });
        DB::statement("ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_status_check
            CHECK (status IN ('draft','issued','paid','cancelled'))");
        DB::statement('ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_total_check
            CHECK (total = subtotal + tax_amount)');
        DB::statement('ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_paid_check
            CHECK (paid_amount >= 0 AND paid_amount <= total)');
        // Faktur pajak tanpa nomornya bukan faktur pajak; nomor tanpa saklarnya tidak akan pernah terbaca.
        DB::statement('ALTER TABLE purchase_invoices ADD CONSTRAINT purchase_invoices_tax_check CHECK (
            (has_tax_invoice = true AND tax_invoice_no IS NOT NULL)
            OR (has_tax_invoice = false AND tax_amount = 0)
        )');
        // Lihat keputusan (1): satu nomor faktur per supplier, hanya bila nomornya diisi.
        DB::statement('CREATE UNIQUE INDEX purchase_invoices_supplier_no_unique
            ON purchase_invoices (company_id, supplier_id, supplier_invoice_no)
            WHERE supplier_invoice_no IS NOT NULL');
        Rls::enable('purchase_invoices');

        Schema::create('purchase_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 200);
            // Komentar ditulis pada kolomnya, sebelum ->constrained(): sesudahnya yang dikembalikan
            // adalah definisi foreign key, dan ->comment() di sana hilang tanpa suara.
            $table->foreignUuid('account_id')
                ->comment('Akun yang didebit: persediaan, beban, atau uang muka')
                ->constrained('accounts');
            $table->uuid('ingredient_id')->nullable()->comment('Diisi bila barisnya berasal dari penerimaan barang');
            $table->foreign('ingredient_id')->references('id')->on('ingredients');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('amount', 18, 2);
            $table->timestampsTz();

            $table->unique(['purchase_invoice_id', 'line_no']);
        });
        DB::statement('ALTER TABLE purchase_invoice_lines ADD CONSTRAINT purchase_invoice_lines_amount_check CHECK (amount > 0)');
        Rls::enable('purchase_invoice_lines');

        /* ---------- AP-03: pelunasan, sebagai alokasi ---------- */
        Schema::create('purchase_invoice_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices');
            $table->uuid('payment_advice_id')->nullable()->comment('Pelunasan lewat advis bayar (SPPK)');
            $table->foreign('payment_advice_id')->references('id')->on('payment_advices');
            $table->decimal('amount', 18, 2);
            $table->date('paid_on');
            $table->timestampsTz();

            $table->index(['company_id', 'purchase_invoice_id']);
        });
        DB::statement('ALTER TABLE purchase_invoice_payments ADD CONSTRAINT purchase_invoice_payments_amount_check CHECK (amount > 0)');
        Rls::enable('purchase_invoice_payments');

        /*
         * SPPK boleh menunjuk faktur yang hendak dilunasi. Dibuat tabel tersendiri, bukan kolom di
         * payment_requests, karena satu pengajuan sering melunasi beberapa faktur sekaligus — itu
         * justru cara paling lazim membayar supplier: sekali transfer untuk tagihan sebulan.
         */
        Schema::create('payment_request_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignUuid('purchase_invoice_id')->constrained('purchase_invoices');
            $table->decimal('amount', 18, 2);
            $table->timestampsTz();

            $table->unique(['payment_request_id', 'purchase_invoice_id']);
        });
        DB::statement('ALTER TABLE payment_request_invoices ADD CONSTRAINT payment_request_invoices_amount_check CHECK (amount > 0)');
        Rls::enable('payment_request_invoices');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_invoices');
        Schema::dropIfExists('purchase_invoice_payments');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['npwp', 'is_pkp', 'bank_name', 'bank_account_number', 'bank_account_holder']);
        });
    }
};
