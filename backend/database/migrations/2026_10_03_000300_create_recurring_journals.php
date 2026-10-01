<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal berulang (ACC-06): sewa, amortisasi, penyusutan, dan beban tetap bulanan lain.
 *
 * Yang dihindari di sini bukan pekerjaan mengetiknya — itu cuma beberapa menit sebulan — melainkan
 * **hari ketika seseorang lupa**. Beban sewa yang terlewat satu bulan membuat laba bulan itu
 * terlihat lebih besar dari yang sebenarnya, dan kesalahan seperti itu baru ketahuan saat tutup
 * tahun. Templat yang berjalan sendiri membuat kealpaan menjadi hal yang mustahil, bukan hal yang
 * jarang.
 *
 * Barisnya disimpan sebagai jsonb, bukan tabel anak: templat tidak pernah dilaporkan, dicari, atau
 * dijumlah — ia hanya dibaca utuh sekali sebulan lalu disalin menjadi jurnal sungguhan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 300);
            $table->unsignedSmallInteger('day_of_month')->default(1)
                ->comment('Tanggal jurnal dibuat; 29–31 jatuh ke akhir bulan pada bulan pendek');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_generated_on')->nullable();
            $table->jsonb('lines');
            $table->uuid('created_by');
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();

            $table->index(['company_id', 'is_active']);
        });

        DB::statement('ALTER TABLE recurring_journals ADD CONSTRAINT recurring_journals_day_check
            CHECK (day_of_month BETWEEN 1 AND 31)');

        Rls::enable('recurring_journals');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journals');
    }
};
