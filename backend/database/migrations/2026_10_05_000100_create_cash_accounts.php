<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kas & bank (Kelompok 5: CSH-01, CSH-02, CSH-03).
 *
 * Tiga keputusan bentuk data yang menentukan sisanya:
 *
 * 1. **Satu rekening = satu akun buku besar, dan hubungannya satu-satu.** Rekening nyata (BCA
 *    1234, Kas Kecil Kaliurang) butuh tempat menyimpan nomor rekening, pemiliknya, dan hasil
 *    rekonsiliasinya — hal-hal yang tidak punya tempat di bagan akun. Tetapi kalau dua rekening
 *    menunjuk akun buku besar yang sama, saldo per rekening **tidak dapat dihitung lagi** dari buku
 *    besar, dan rekonsiliasi bank kehilangan dasarnya. Karena itu `account_id` dibuat unik.
 * 2. **Saldo tidak disimpan.** Tidak ada kolom `balance` di sini, sengaja: saldo yang disimpan dan
 *    saldo yang dihitung dari buku besar pasti berbeda suatu hari, dan yang salah selalu yang
 *    disimpan. Saldo rekening dihitung dari `journal_lines` akun itu, sama seperti akun lain.
 * 3. **Satu tabel untuk kas masuk, kas keluar, dan transfer.** Ketiganya adalah mutasi kas dengan
 *    lawan yang berbeda — lawan transfer kebetulan rekening lain. Memisahkannya jadi tiga tabel
 *    hanya menggandakan aturan yang sama persis (nilai > 0, jurnal seimbang, periode terbuka).
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- CSH-01: master kas & bank ---------- */
        Schema::create('cash_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 10)->default('bank')->comment('cash = kas fisik, bank = rekening bank');
            $table->string('bank_name', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('account_holder', 100)->nullable();
            // Satu-satu dengan akun buku besar: lihat alasan (1) di atas.
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->uuid('outlet_id')->nullable()->comment('Diisi untuk kas kecil milik outlet tertentu');
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->boolean('is_active')->default(true);
            $table->string('notes', 300)->nullable();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'account_id']);
        });
        Rls::enable('cash_accounts');

        /* ---------- CSH-02 & CSH-03: mutasi kas ---------- */
        Schema::create('cash_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('kind', 10)->comment('in = kas masuk, out = kas keluar, transfer = antar rekening');
            $table->date('transaction_date');
            $table->foreignUuid('cash_account_id')->constrained('cash_accounts');
            /*
             * Lawan transaksinya. Untuk masuk/keluar ia akun buku besar (pendapatan lain, beban,
             * setoran modal); untuk transfer ia rekening tujuan. Hanya satu yang terisi, dijaga
             * CHECK constraint di bawah — baris yang mengisi keduanya tidak punya arti jurnal.
             */
            $table->uuid('contra_account_id')->nullable();
            $table->foreign('contra_account_id')->references('id')->on('accounts');
            $table->uuid('counter_cash_account_id')->nullable();
            $table->foreign('counter_cash_account_id')->references('id')->on('cash_accounts');
            $table->decimal('amount', 18, 2);
            $table->uuid('outlet_id')->nullable();
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->string('description', 300);
            $table->string('reference', 100)->nullable()->comment('Nomor bukti/transfer dari bank');
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'transaction_date']);
            $table->index(['company_id', 'cash_account_id', 'transaction_date']);
        });
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_kind_check
            CHECK (kind IN ('in','out','transfer'))");
        DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_amount_check CHECK (amount > 0)');
        /*
         * Bentuk barisnya dijaga basis data, bukan hanya layanan: transfer wajib punya rekening
         * tujuan dan tidak boleh punya akun lawan; masuk/keluar sebaliknya. Satu baris yang salah
         * bentuk akan menghasilkan jurnal timpang — dan jurnal timpang baru ketahuan di neraca saldo,
         * jauh dari tempat kesalahannya dibuat.
         */
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_shape_check CHECK (
            (kind = 'transfer' AND counter_cash_account_id IS NOT NULL AND contra_account_id IS NULL)
            OR (kind <> 'transfer' AND counter_cash_account_id IS NULL AND contra_account_id IS NOT NULL)
        )");
        // Memindahkan uang ke rekening yang sama adalah salah ketik, bukan transaksi.
        DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_self_transfer_check
            CHECK (counter_cash_account_id IS NULL OR counter_cash_account_id <> cash_account_id)');
        Rls::enable('cash_transactions');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('cash_accounts');
    }
};
