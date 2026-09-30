<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inti akuntansi: bagan akun, periode, jurnal, buku besar (ACC-01, 03, 04, 05, 07, 08).
 *
 * Menggantikan layar prototipe `app/Filament/Pages/Akuntansi` yang seluruh angkanya ditulis tangan
 * di `DemoBooks.php`. Ini yang pertama kali menyimpan angka akuntansi ke basis data.
 *
 * Keputusan user 1 Okt 2026: COA dan jurnal **milik tiap company**, sama seperti seluruh data lain —
 * RLS tetap utuh dan tidak ada perubahan pada fondasi tenancy. Lapisan Group/Holding dan konsolidasi
 * (GRP-01, CON-01..07) menyusul; `journal_lines.counterparty_company_id` sudah disiapkan sejak
 * sekarang karena menambahkannya belakangan berarti membongkar jurnal yang sudah diposting.
 *
 * Dua jaminan dijaga di tingkat basis data, bukan hanya di kode:
 * 1. Satu baris jurnal mengisi debit ATAU kredit, tidak pernah keduanya dan tidak pernah nol.
 * 2. Jurnal yang sudah diposting tidak bisa diubah atau dihapus barisnya. Koreksi hanya lewat
 *    jurnal balik. Ini inti kepercayaan sebuah buku besar; kalau hanya dijaga kode aplikasi, satu
 *    skrip perbaikan data yang ceroboh cukup untuk menghancurkannya tanpa jejak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 12)->comment('asset | liability | equity | revenue | cogs | expense');
            $table->string('normal_balance', 6)->comment('debit | credit');
            $table->uuid('parent_id')->nullable();
            // Hanya akun daun yang boleh dijurnal; akun induk dipakai untuk menjumlah di laporan.
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            /*
             * Akun bawaan template yang diacu pemetaan jurnal otomatis (ACC-10). Boleh dinonaktifkan
             * atau diganti namanya, tetapi tidak boleh dihapus — menghapusnya membuat jurnal
             * penjualan kehilangan tujuan tanpa ada yang menyadarinya sampai tutup buku.
             */
            $table->boolean('is_system')->default(false);
            $table->string('description', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'parent_id']);
        });
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('asset','liability','equity','revenue','cogs','expense'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check CHECK (normal_balance IN ('debit','credit'))");
        // Acuan ke tabel sendiri dipasang terpisah: saat blok create dijalankan, kunci primernya
        // belum ada sehingga Postgres menolak ("no unique constraint matching given keys").
        Schema::table('accounts', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('accounts'));
        Rls::enable('accounts');

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('open')->comment('open | closed');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->foreign('closed_by')->references('id')->on('users');
            $table->string('close_note', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'year', 'month']);
            $table->index(['company_id', 'starts_on']);
        });
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_month_check CHECK (month BETWEEN 1 AND 12)');
        Rls::enable('accounting_periods');

        Schema::create('journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->date('journal_date');
            $table->foreignUuid('period_id')->constrained('accounting_periods');
            $table->string('description', 300);
            $table->string('status', 10)->default('draft')->comment('draft | posted | reversed');
            $table->string('source', 20)->default('manual')->comment('manual | sales | depreciation | …');
            // Kunci idempoten untuk jurnal otomatis (ACC-11): satu kejadian bisnis = satu jurnal.
            $table->string('source_key', 120)->nullable();
            $table->uuid('reverses_journal_id')->nullable()->comment('Jurnal yang dibalik oleh jurnal ini');
            $table->uuid('reversed_by_journal_id')->nullable();
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->uuid('posted_by')->nullable();
            $table->foreign('posted_by')->references('id')->on('users');
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'journal_date']);
            $table->index(['company_id', 'status']);
        });
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_check CHECK (status IN ('draft','posted','reversed'))");
        DB::statement('CREATE UNIQUE INDEX journals_source_key_unique ON journals (company_id, source_key) WHERE source_key IS NOT NULL');
        Schema::table('journals', function (Blueprint $table): void {
            $table->foreign('reverses_journal_id')->references('id')->on('journals');
            $table->foreign('reversed_by_journal_id')->references('id')->on('journals');
        });
        Rls::enable('journals');

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->smallInteger('line_no');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo', 300)->nullable();
            // Dimensi akuntansi (ACC-03): laba rugi per brand/outlet tanpa memecah bagan akun.
            $table->uuid('brand_id')->nullable();
            $table->foreign('brand_id')->references('id')->on('brands');
            $table->uuid('outlet_id')->nullable();
            $table->foreign('outlet_id')->references('id')->on('outlets');
            // Disiapkan untuk eliminasi antar-entitas (CON-03); belum dipakai di putaran ini.
            $table->uuid('counterparty_company_id')->nullable();
            $table->foreign('counterparty_company_id')->references('id')->on('companies');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['journal_id', 'line_no']);
            $table->index(['company_id', 'account_id']);
            $table->index(['company_id', 'outlet_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_check CHECK (debit >= 0 AND credit >= 0 AND (debit = 0) <> (credit = 0))');
        Rls::enable('journal_lines');

        /*
         * Jurnal terposting bersifat final. Yang boleh berubah hanya penanda bahwa ia sudah dibalik
         * (`reversed_by_journal_id` + `status`); tanggal, keterangan, dan nomornya tidak.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journals_posted_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'posted' AND (
                    NEW.journal_date IS DISTINCT FROM OLD.journal_date
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.period_id IS DISTINCT FROM OLD.period_id
                    OR NEW.source IS DISTINCT FROM OLD.source
                    OR NEW.source_key IS DISTINCT FROM OLD.source_key
                ) THEN
                    RAISE EXCEPTION 'Jurnal yang sudah diposting tidak dapat diubah (%). Koreksi lewat jurnal balik.', OLD.number;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journals_posted_immutable_trg
                BEFORE UPDATE ON journals
                FOR EACH ROW EXECUTE FUNCTION journals_posted_immutable();

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

            CREATE TRIGGER journal_lines_posted_immutable_trg
                BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_lines_posted_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_posted_immutable_trg ON journal_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS journals_posted_immutable_trg ON journals');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_lines_posted_immutable()');
        DB::unprepared('DROP FUNCTION IF EXISTS journals_posted_immutable()');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounts');
    }
};
