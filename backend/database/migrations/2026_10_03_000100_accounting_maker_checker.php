<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maker–checker, lampiran, dan soft close (ACC-05, ACC-04).
 *
 * Tiga perubahan yang saling berkaitan:
 *
 * 1. **Jurnal punya status "diajukan".** Yang menyatakan angkanya benar (pengaju) harus orang lain
 *    daripada yang memasukkannya ke buku besar (pemosting) — keputusan user 1 Okt 2026. Tanpa
 *    status antara, tidak ada momen di mana jurnal "sudah siap tetapi belum masuk buku".
 * 2. **Lampiran.** Jurnal tanpa bukti hanya pernyataan. Berkasnya TIDAK disajikan publik seperti
 *    foto menu: foto nota memuat nama, nominal, dan kadang NPWP.
 * 3. **Soft close.** Menutup periode selama ini hanya satu tingkat. Di praktiknya ada keadaan
 *    "laporan sudah terbit, koreksi kecil masih mungkin" — itulah soft close: posting masih boleh
 *    tetapi tidak bisa terjadi diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            $table->uuid('submitted_by')->nullable()->after('created_by');
            $table->foreign('submitted_by')->references('id')->on('users');
            $table->timestampTz('submitted_at')->nullable()->after('submitted_by');
            $table->uuid('rejected_by')->nullable()->after('posted_at');
            $table->foreign('rejected_by')->references('id')->on('users');
            $table->timestampTz('rejected_at')->nullable()->after('rejected_by');
            $table->string('reject_reason', 300)->nullable()->after('rejected_at');
        });

        DB::statement('ALTER TABLE journals DROP CONSTRAINT journals_status_check');
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_check CHECK (status IN ('draft','submitted','posted','reversed'))");

        /*
         * Jurnal yang sudah DIAJUKAN ikut dibekukan barisnya. Kalau tidak, pengaju bisa mengubah
         * angkanya setelah verifikator membacanya tetapi sebelum ia menekan Posting — dan seluruh
         * gunanya pemisahan tugas hilang di celah itu.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_posted_immutable() RETURNS trigger AS $$
            DECLARE current_status text;
            BEGIN
                SELECT status INTO current_status FROM journals
                 WHERE id = COALESCE(NEW.journal_id, OLD.journal_id);
                IF current_status IN ('submitted', 'posted', 'reversed') THEN
                    RAISE EXCEPTION 'Baris jurnal yang sudah diajukan atau diposting tidak dapat diubah. Kembalikan ke draft, atau koreksi lewat jurnal balik.';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::create('journal_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->string('path', 200);
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->uuid('uploaded_by');
            $table->foreign('uploaded_by')->references('id')->on('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'journal_id']);
        });
        Rls::enable('journal_attachments');

        // Soft close: posting masih boleh, tetapi tercatat khusus dan ditandai di laporan.
        // Kolomnya varchar(10) sejak awal — 'soft_closed' 11 huruf, jadi harus dilebarkan dulu.
        DB::statement('ALTER TABLE accounting_periods ALTER COLUMN status TYPE varchar(15)');
        DB::statement('ALTER TABLE accounting_periods DROP CONSTRAINT accounting_periods_status_check');
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open','soft_closed','closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_attachments');

        DB::statement("UPDATE journals SET status = 'draft' WHERE status = 'submitted'");
        DB::statement('ALTER TABLE journals DROP CONSTRAINT journals_status_check');
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_check CHECK (status IN ('draft','posted','reversed'))");

        Schema::table('journals', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['submitted_by', 'submitted_at', 'rejected_by', 'rejected_at', 'reject_reason']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_posted_immutable() RETURNS trigger AS $$
            DECLARE current_status text;
            BEGIN
                SELECT status INTO current_status FROM journals
                 WHERE id = COALESCE(NEW.journal_id, OLD.journal_id);
                IF current_status IN ('posted', 'reversed') THEN
                    RAISE EXCEPTION 'Baris jurnal yang sudah diposting tidak dapat diubah atau dihapus. Koreksi lewat jurnal balik.';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement("UPDATE accounting_periods SET status = 'closed' WHERE status = 'soft_closed'");
        DB::statement('ALTER TABLE accounting_periods DROP CONSTRAINT accounting_periods_status_check');
        DB::statement('ALTER TABLE accounting_periods ALTER COLUMN status TYPE varchar(10)');
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open','closed'))");
    }
};
