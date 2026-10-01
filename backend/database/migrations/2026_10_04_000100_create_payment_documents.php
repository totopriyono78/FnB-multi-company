<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siklus pengeluaran tanpa kertas (Kelompok 4: DOC-01, 02, 04, 05, 09, 10).
 *
 * Inilah pengganti pemeriksaan nota manual yang jadi keluhan terbesar klien: cabang mengajukan
 * pembayaran berikut foto notanya, pusat memeriksa dan menandatangani sesuai batas wewenang, lalu
 * terbit advis bayar yang menghasilkan jurnalnya sendiri.
 *
 * Empat keputusan bentuk data yang perlu diingat:
 *
 * 1. **Lampiran dijadikan polimorfik.** Ia lahir khusus untuk jurnal kemarin, tetapi bukti yang
 *    sama persis dibutuhkan SPPK, advis bayar, dan nanti faktur pembelian, aset, dan kontrak sewa.
 *    Memindahkannya sekarang — saat pemiliknya baru satu jenis — jauh lebih murah daripada nanti.
 * 2. **Matriks wewenang berbentuk band nilai × tingkat.** Satu baris = "pada nilai sampai sekian,
 *    tingkat ke-N ditandatangani peran ini". Dibuat data, bukan kode, karena kebijakan tanda tangan
 *    adalah hal yang paling sering berubah di grup usaha — dan perubahannya tidak boleh menunggu rilis.
 * 3. **Tanda tangan disimpan sebagai baris tersendiri**, bukan kolom `approved_by` tunggal.
 *    Persetujuan berjenjang berarti ada beberapa orang, pada waktu berbeda, masing-masing dengan
 *    catatannya. Menyimpannya di satu kolom berarti membuang semua kecuali yang terakhir.
 * 4. **Satu SPPK boleh dibayar beberapa kali.** Pembayaran sebagian adalah hal biasa; memaksa satu
 *    SPPK = satu transfer hanya akan membuat orang memecah pengajuannya dan menghindari sistem.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- DOC-01: lampiran untuk dokumen apa pun ---------- */
        Schema::table('journal_attachments', function (Blueprint $table): void {
            $table->string('owner_type', 40)->nullable()->after('company_id');
            $table->uuid('owner_id')->nullable()->after('owner_type');
        });
        DB::statement("UPDATE journal_attachments SET owner_type = 'journal', owner_id = journal_id");
        Schema::table('journal_attachments', function (Blueprint $table): void {
            $table->dropForeign(['journal_id']);
            $table->dropColumn('journal_id');
            $table->string('owner_type', 40)->nullable(false)->change();
            $table->uuid('owner_id')->nullable(false)->change();
            $table->index(['company_id', 'owner_type', 'owner_id']);
        });
        Schema::rename('journal_attachments', 'document_attachments');

        /* ---------- DOC-09: matriks batas wewenang ---------- */
        Schema::create('approval_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('doc_type', 20)->default('payment_request');
            /*
             * Batas atas band. NULL = tanpa batas (band paling atas). Suatu nilai memakai band
             * dengan `max_amount` terkecil yang masih >= nilainya.
             */
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->unsignedSmallInteger('level');
            $table->string('role', 50)->comment('Nama peran yang harus menandatangani di tingkat ini');
            $table->timestampsTz();

            $table->index(['company_id', 'doc_type']);
        });
        DB::statement('ALTER TABLE approval_rules ADD CONSTRAINT approval_rules_level_check CHECK (level BETWEEN 1 AND 5 AND (max_amount IS NULL OR max_amount > 0))');
        // NULL dianggap berbeda satu sama lain di indeks unik Postgres: dua indeks parsial.
        DB::statement('CREATE UNIQUE INDEX approval_rules_band_unique ON approval_rules (company_id, doc_type, max_amount, level) WHERE max_amount IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX approval_rules_top_unique ON approval_rules (company_id, doc_type, level) WHERE max_amount IS NULL');
        Rls::enable('approval_rules');

        /* ---------- DOC-04: SPPK ---------- */
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->date('request_date');
            $table->date('due_date')->nullable();
            $table->uuid('outlet_id')->nullable()->comment('Unit pengaju; kosong = kantor pusat');
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->uuid('supplier_id')->nullable();
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->string('payee_name', 150)->comment('Nama penerima; disalin agar tetap terbaca bila master supplier berubah');
            $table->decimal('amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            // Komentar ditulis pada kolomnya, sebelum ->constrained(): setelahnya yang dikembalikan
            // adalah definisi foreign key, dan ->comment() di sana hilang tanpa suara.
            $table->foreignUuid('expense_account_id')
                ->comment('Akun yang didebit saat dibayar: beban, uang muka, atau utang usaha')
                ->constrained('accounts');
            $table->string('description', 300);
            $table->string('status', 12)->default('draft');
            $table->unsignedSmallInteger('required_levels')->default(1)->comment('Dibekukan saat diajukan, agar perubahan matriks tidak mengubah dokumen berjalan');
            $table->uuid('requested_by');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->foreign('rejected_by')->references('id')->on('users');
            $table->timestampTz('rejected_at')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'request_date']);
        });
        DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_status_check
            CHECK (status IN ('draft','submitted','approved','rejected','paid','cancelled'))");
        DB::statement('ALTER TABLE payment_requests ADD CONSTRAINT payment_requests_amount_check
            CHECK (amount > 0 AND paid_amount >= 0 AND paid_amount <= amount)');
        Rls::enable('payment_requests');

        Schema::create('payment_request_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('role', 50);
            $table->uuid('approved_by');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->timestampTz('approved_at');
            $table->string('note', 300)->nullable();

            $table->unique(['payment_request_id', 'level']);
        });
        Rls::enable('payment_request_approvals');

        /* ---------- DOC-05: advis bayar ---------- */
        Schema::create('payment_advices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('payment_request_id')->constrained('payment_requests');
            $table->date('paid_on');
            $table->decimal('amount', 18, 2);
            $table->foreignUuid('bank_account_id')->constrained('accounts');
            $table->string('reference', 100)->nullable()->comment('Nomor referensi transfer');
            $table->string('note', 300)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'paid_on']);
        });
        DB::statement('ALTER TABLE payment_advices ADD CONSTRAINT payment_advices_amount_check CHECK (amount > 0)');
        Rls::enable('payment_advices');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_advices');
        Schema::dropIfExists('payment_request_approvals');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('approval_rules');

        Schema::rename('document_attachments', 'journal_attachments');
        Schema::table('journal_attachments', function (Blueprint $table): void {
            $table->uuid('journal_id')->nullable()->after('company_id');
        });
        DB::statement("UPDATE journal_attachments SET journal_id = owner_id WHERE owner_type = 'journal'");
        DB::statement('DELETE FROM journal_attachments WHERE journal_id IS NULL');
        Schema::table('journal_attachments', function (Blueprint $table): void {
            $table->uuid('journal_id')->nullable(false)->change();
            $table->foreign('journal_id')->references('id')->on('journals')->cascadeOnDelete();
            $table->dropIndex(['company_id', 'owner_type', 'owner_id']);
            $table->dropColumn(['owner_type', 'owner_id']);
        });
    }
};
