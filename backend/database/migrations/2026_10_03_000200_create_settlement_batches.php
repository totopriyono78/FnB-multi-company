<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pencairan settlement (ACC-12).
 *
 * Tanpa tabel ini, jurnal penjualan hanya pernah MENAMBAH piutang settlement dan tidak pernah
 * menguranginya: tiap transaksi non-tunai mendebit 1201/1202, dan tidak ada apa pun yang
 * memindahkannya ke bank saat dananya cair. Dalam sebulan Neraca akan menampilkan piutang raksasa
 * yang tidak pernah ada di dunia nyata — cacat yang tidak kelihatan sampai seseorang membaca
 * laporannya dengan sungguh-sungguh.
 *
 * Satu baris = satu kali dana masuk rekening dari satu penyedia pembayaran.
 *
 *     kotor = bersih + biaya
 *
 * `kotor` adalah nilai piutang yang dibersihkan, `bersih` yang benar-benar masuk bank, dan `biaya`
 * potongan yang baru muncul saat pencairan (di luar MDR yang sudah diakui saat penjualan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('outlet_id')->nullable()->comment('Kosong = gabungan seluruh outlet');
            $table->foreign('outlet_id')->references('id')->on('outlets');
            $table->string('method', 20);
            $table->date('settled_on');
            $table->decimal('gross_amount', 18, 2)->comment('Piutang settlement yang dibersihkan');
            $table->decimal('fee_amount', 18, 2)->default(0)->comment('Potongan saat pencairan, di luar MDR');
            $table->decimal('net_amount', 18, 2)->comment('Yang benar-benar masuk rekening');
            $table->uuid('bank_account_id')->comment('Akun kas/bank tujuan');
            $table->foreign('bank_account_id')->references('id')->on('accounts');
            $table->uuid('fee_account_id')->nullable();
            $table->foreign('fee_account_id')->references('id')->on('accounts');
            $table->string('reference', 100)->nullable()->comment('Nomor settlement dari penyedia');
            $table->string('note', 300)->nullable();
            $table->uuid('journal_id')->nullable();
            $table->foreign('journal_id')->references('id')->on('journals');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->index(['company_id', 'method', 'settled_on']);
        });

        DB::statement('ALTER TABLE settlement_batches ADD CONSTRAINT settlement_batches_amount_check
            CHECK (gross_amount > 0 AND fee_amount >= 0 AND net_amount >= 0 AND gross_amount = net_amount + fee_amount)');

        /*
         * Satu nomor settlement dari penyedia hanya boleh dicatat sekali. Mencatatnya dua kali
         * berarti piutang dibersihkan dua kali sementara uangnya cuma masuk sekali — dan itu baru
         * ketahuan saat rekonsiliasi bank, berminggu-minggu kemudian.
         */
        DB::statement("CREATE UNIQUE INDEX settlement_batches_reference_unique
            ON settlement_batches (company_id, method, reference) WHERE reference IS NOT NULL AND reference <> ''");

        Rls::enable('settlement_batches');
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_batches');
    }
};
