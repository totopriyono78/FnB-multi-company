<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemetaan kejadian bisnis → akun untuk jurnal otomatis (ACC-10).
 *
 * Satu baris = satu "slot": pendapatan (per kategori menu), pajak, service charge, diskon,
 * pembulatan, retur, kas laci, piutang settlement per metode bayar, biaya MDR, selisih kas, dan
 * kas masuk/keluar yang belum diklasifikasi.
 *
 * Kenapa tabel, bukan konstanta di kode: tiap entitas boleh punya bagan akun sendiri, dan yang
 * paling sering berubah justru pemetaan pendapatan per kategori menu — kategori baru lahir tiap
 * kali menu bertambah. Pemetaan yang ditanam di kode berarti tiap perubahan menu menunggu rilis.
 *
 * `ref_id` dipakai slot yang berdimensi (pendapatan → id kategori menu). NULL = aturan bawaan slot
 * itu. Postgres menganggap NULL berbeda satu sama lain di indeks unik, jadi keunikannya dijaga dua
 * indeks parsial — tanpa itu satu slot bisa punya banyak baris bawaan yang saling bertentangan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('slot', 40);
            $table->uuid('ref_id')->nullable()->comment('Dimensi slot, mis. id kategori menu; NULL = bawaan');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->timestampsTz();

            $table->index(['company_id', 'slot']);
        });

        DB::statement('CREATE UNIQUE INDEX journal_mappings_default_unique ON journal_mappings (company_id, slot) WHERE ref_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX journal_mappings_ref_unique ON journal_mappings (company_id, slot, ref_id) WHERE ref_id IS NOT NULL');

        Rls::enable('journal_mappings');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_mappings');
    }
};
