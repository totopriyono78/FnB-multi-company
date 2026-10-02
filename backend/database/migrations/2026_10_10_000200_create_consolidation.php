<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Konsolidasi manajerial (CON-01, CON-02, CON-05 manual, CON-06, CON-07).
 *
 * Seluruh tabel di sini milik **entitas holding**: `company_id`-nya adalah company holding dan
 * kebijakan RLS-nya biasa. Lihat migrasi `create_groups` untuk alasannya.
 *
 * ## Tanda angka: mengikuti KELOMPOK akun, sama seperti laporan keuangan
 *
 * `opening`, `period`, dan `closing` disimpan **bertanda menurut kelompok akunnya** — aset/HPP/beban
 * positif bila debit melebihi kredit, liabilitas/ekuitas/pendapatan positif bila kredit melebihi
 * debit. Ini aturan yang sama persis dengan `FinancialStatements::balances()`, dan dipilih supaya
 * penjumlahan antar entitas di kertas kerja cukup penjumlahan biasa: akun lawan (Diskon Penjualan,
 * Akumulasi Penyusutan) otomatis tampil negatif dan benar-benar mengurangi kelompoknya.
 *
 * Tiga kolom karena dua laporan membutuhkan potongan waktu yang berbeda dari data yang sama:
 * Neraca memakai `closing` (saldo kumulatif per tanggal akhir), Laba Rugi memakai `period` (mutasi
 * dalam periode), dan `opening` disimpan agar perubahan ekuitas serta pemeriksaan silang bisa
 * dihitung tanpa membaca ulang buku besar anak usaha.
 *
 * ## Kenapa snapshot, bukan membaca langsung
 *
 * Membaca buku besar anak usaha saat halaman dibuka berarti halaman konsolidasi harus berjalan
 * lintas tenant — tepat hal yang paling ingin dihindari. Snapshot memindahkan satu-satunya momen
 * lintas tenant ke satu proses batch yang bisa diuji, dicatat, dan diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- CON-01: satu proses konsolidasi untuk satu grup & satu periode ---------- */
        Schema::create('consolidation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('groups');
            $table->string('number', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft')->comment('draft = boleh dijalankan ulang, final = terkunci');
            $table->string('label', 120)->nullable()->comment('mis. "Oktober 2026"');
            $table->timestampTz('generated_at')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->foreign('generated_by')->references('id')->on('users');
            $table->timestampTz('finalized_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->foreign('finalized_by')->references('id')->on('users');
            $table->unsignedSmallInteger('entity_count')->default(0);
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            // Satu grup hanya punya satu proses per periode: dua yang berbeda untuk periode sama
            // berarti ada dua jawaban atas satu pertanyaan, dan tidak ada cara memilihnya.
            $table->unique(['company_id', 'group_id', 'period_start', 'period_end']);
        });
        DB::statement("ALTER TABLE consolidation_runs ADD CONSTRAINT consolidation_runs_status_check
            CHECK (status IN ('draft','final'))");
        DB::statement('ALTER TABLE consolidation_runs ADD CONSTRAINT consolidation_runs_period_check
            CHECK (period_end >= period_start)');
        Rls::enable('consolidation_runs');

        /* ---------- Entitas yang ikut, beserta keadaan bukunya saat ditarik ---------- */
        Schema::create('consolidation_entities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('consolidation_runs')->cascadeOnDelete();
            /*
             * Sengaja TANPA foreign key ke `companies`: baris ini dibaca oleh entitas holding,
             * sedangkan baris company anak usaha tidak terlihat olehnya. Kode dan namanya disalin
             * supaya kertas kerja tidak pernah perlu membaca `companies` lintas tenant.
             */
            $table->uuid('source_company_id');
            $table->string('source_code', 40);
            $table->string('source_name', 120);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->unsignedInteger('posted_journal_count')->default(0);
            $table->unsignedInteger('draft_journal_count')->default(0);
            $table->date('last_journal_date')->nullable();
            // Buku besar yang tidak seimbang harus kelihatan di sini, bukan baru muncul sebagai
            // neraca konsolidasi yang timpang — pada titik itu entitas penyebabnya sudah tak terlacak.
            $table->boolean('out_of_balance')->default(false);
            $table->decimal('imbalance', 18, 2)->default(0);
            $table->timestampsTz();

            $table->unique(['run_id', 'source_company_id']);
        });
        Rls::enable('consolidation_entities');

        /* ---------- Saldo per entitas per akun: inti snapshot ---------- */
        Schema::create('consolidation_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('consolidation_runs')->cascadeOnDelete();
            $table->uuid('source_company_id');
            $table->string('account_code', 20);
            $table->string('account_name', 150);
            $table->string('account_type', 20);
            /*
             * Hasil pemetaan (CON-02). Bila entitas memakai bagan akun standar — dan semuanya
             * memakai `AccountTemplate` yang sama — ketiganya sama dengan kolom di atas.
             */
            $table->string('target_code', 20);
            $table->string('target_name', 150);
            $table->string('target_type', 20);
            $table->decimal('opening', 18, 2)->default(0);
            $table->decimal('period', 18, 2)->default(0);
            $table->decimal('closing', 18, 2)->default(0);
            $table->timestampsTz();

            $table->unique(['run_id', 'source_company_id', 'account_code']);
            $table->index(['run_id', 'target_code']);
        });
        Rls::enable('consolidation_balances');

        /* ---------- CON-05 (bagian manual): eliminasi & penyesuaian level konsolidasi ---------- */
        Schema::create('consolidation_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('run_id')->constrained('consolidation_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('kind', 20)->default('elimination')->comment('elimination = saling hapus antar entitas, adjustment = penyesuaian lain');
            /*
             * **Dua sisi dalam satu baris, dan itu disengaja.** Jurnal eliminasi berbaris-baris akan
             * selalu bisa timpang, dan ketimpangannya baru terlihat di neraca konsolidasi. Dengan
             * bentuk ini ketimpangan tidak mungkin ada — bukan karena divalidasi, tetapi karena tidak
             * ada tempat untuk menyimpannya. Eliminasi yang butuh lebih dari dua sisi ditulis sebagai
             * dua baris, dan itu harga yang murah untuk jaminan sekuat ini.
             *
             * Akun yang ditunjuk adalah akun milik entitas holding — bagan akun holding sekaligus
             * menjadi bagan akun konsolidasi.
             */
            $table->foreignUuid('debit_account_id')->constrained('accounts');
            $table->foreignUuid('credit_account_id')->constrained('accounts');
            $table->decimal('amount', 18, 2);
            $table->string('description', 300);
            $table->string('counterparty_note', 200)->nullable()->comment('Entitas yang saling dihapus, mis. "Pusat ↔ Kaliurang"');
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['run_id', 'sequence']);
        });
        DB::statement("ALTER TABLE consolidation_adjustments ADD CONSTRAINT consolidation_adjustments_kind_check
            CHECK (kind IN ('elimination','adjustment'))");
        DB::statement('ALTER TABLE consolidation_adjustments ADD CONSTRAINT consolidation_adjustments_amount_check
            CHECK (amount > 0)');
        // Mendebit dan mengkredit akun yang sama tidak menghapus apa pun; itu salah pilih, bukan eliminasi.
        DB::statement('ALTER TABLE consolidation_adjustments ADD CONSTRAINT consolidation_adjustments_distinct_check
            CHECK (debit_account_id <> credit_account_id)');
        Rls::enable('consolidation_adjustments');

        /* ---------- CON-02: pemetaan akun lokal → akun konsolidasi ---------- */
        Schema::create('consolidation_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            // Null berarti berlaku untuk semua entitas anggota; terisi berarti hanya entitas itu.
            $table->uuid('source_company_id')->nullable();
            $table->string('source_code', 20);
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->string('note', 200)->nullable();
            $table->timestampsTz();
        });
        /*
         * Unik parsial: satu aturan umum per kode, dan paling banyak satu aturan khusus per
         * (entitas, kode). Tanpa ini dua aturan bisa saling bertentangan dan yang menang hanya
         * bergantung pada urutan baris — jenis kesalahan yang tidak pernah bisa dijelaskan.
         */
        DB::statement('CREATE UNIQUE INDEX consolidation_mappings_general_unique
            ON consolidation_mappings (company_id, group_id, source_code)
            WHERE source_company_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX consolidation_mappings_specific_unique
            ON consolidation_mappings (company_id, group_id, source_company_id, source_code)
            WHERE source_company_id IS NOT NULL');
        Rls::enable('consolidation_mappings');
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_mappings');
        Schema::dropIfExists('consolidation_adjustments');
        Schema::dropIfExists('consolidation_balances');
        Schema::dropIfExists('consolidation_entities');
        Schema::dropIfExists('consolidation_runs');
    }
};
