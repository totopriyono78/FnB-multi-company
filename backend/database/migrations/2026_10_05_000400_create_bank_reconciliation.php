<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rekonsiliasi bank (Kelompok 5: CSH-04).
 *
 * Inilah pasangan dari "advis bayar belum terbukukan" di antrian verifikasi. Yang satu menemukan
 * uang yang sudah keluar tetapi belum dibukukan; yang ini menemukan **uang yang bergerak di bank
 * tanpa ada catatannya sama sekali** — biaya administrasi, bunga, pendebetan otomatis, dan pada
 * kasus terburuk, pengeluaran yang tidak pernah diajukan siapa pun.
 *
 * Tiga keputusan bentuk data:
 *
 * 1. **Pencocokan menunjuk BARIS JURNAL, bukan dokumen.** Satu transfer bank bisa berasal dari advis
 *    bayar, mutasi kas, atau jurnal manual; satu-satunya hal yang pasti dimiliki ketiganya adalah
 *    baris jurnal pada akun rekening itu. Menunjuk dokumen berarti harus menebak jenisnya dulu.
 * 2. **Satu baris jurnal hanya boleh cocok dengan satu baris rekening koran**, dijaga indeks unik
 *    parsial. Tanpa itu, satu pengeluaran bisa "menjelaskan" dua baris mutasi sekaligus dan
 *    selisihnya hilang dari pandangan.
 * 3. **Hasil rekonsiliasi dapat dikunci.** Setelah dikunci, barisnya tidak bisa diubah: rekonsiliasi
 *    yang masih bisa disunting belakangan bukan kontrol, hanya catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->decimal('closing_balance', 18, 2)->default(0)->comment('Saldo akhir menurut rekening koran');
            $table->string('source_name', 150)->nullable()->comment('Nama berkas yang diimpor');
            $table->timestampTz('locked_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->foreign('locked_by')->references('id')->on('users');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->index(['company_id', 'cash_account_id', 'period_end']);
        });
        DB::statement('ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_period_check
            CHECK (period_end >= period_start)');
        Rls::enable('bank_statements');

        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->date('value_date');
            $table->string('description', 300);
            $table->string('reference', 100)->nullable();
            // Dua kolom, bukan satu bernilai negatif: rekening koran bank Indonesia memang berbentuk
            // debet/kredit, dan menerjemahkannya menjadi satu kolom bertanda membuat impor harus
            // menebak arah — tebakan yang salahnya baru ketahuan saat saldonya tidak cocok.
            $table->decimal('debit', 18, 2)->default(0)->comment('Uang keluar dari rekening');
            $table->decimal('credit', 18, 2)->default(0)->comment('Uang masuk ke rekening');
            $table->uuid('matched_journal_line_id')->nullable();
            $table->foreign('matched_journal_line_id')->references('id')->on('journal_lines');
            $table->timestampTz('matched_at')->nullable();
            $table->uuid('matched_by')->nullable();
            $table->foreign('matched_by')->references('id')->on('users');
            $table->string('match_mode', 10)->nullable()->comment('auto | manual');
            $table->boolean('is_ignored')->default(false)
                ->comment('Baris yang sengaja dinyatakan tidak perlu dicocokkan, dengan alasannya');
            $table->string('ignore_reason', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['bank_statement_id', 'line_no']);
            $table->index(['company_id', 'value_date']);
        });
        DB::statement('ALTER TABLE bank_statement_lines ADD CONSTRAINT bank_statement_lines_amount_check CHECK (
            (debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)
        )');
        // Lihat keputusan (2): satu baris jurnal hanya menjelaskan satu baris rekening koran.
        DB::statement('CREATE UNIQUE INDEX bank_statement_lines_journal_line_unique
            ON bank_statement_lines (company_id, matched_journal_line_id)
            WHERE matched_journal_line_id IS NOT NULL');
        Rls::enable('bank_statement_lines');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
};
