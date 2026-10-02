<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan Group/Holding di atas Company (GRP-01).
 *
 * ## Group BUKAN tenant — dan itu keputusan terpenting di seluruh Kelompok 8
 *
 * Isolasi tenant di sistem ini dijaga PostgreSQL: setiap tabel punya `company_id` dan satu kebijakan
 * RLS yang membandingkannya dengan `app.current_company_id`. Menambah lapisan di atas Company
 * menggoda untuk menjadikan Group kunci tenant kedua — dan di situlah isolasi biasanya bocor, karena
 * sejak itu setiap tabel punya DUA cara terlihat dan hanya satu yang diperiksa.
 *
 * Jadi `company_id` tetap satu-satunya kunci tenant. Group hanya:
 *
 * 1. satu baris master yang **dimiliki entitas holding** (`holding_company_id`), dan
 * 2. sebuah kolom `group_id` di `companies` yang menyatakan keanggotaan.
 *
 * Karena itu pula `groups.company_id` berarti **entitas holding yang memiliki baris grup ini** —
 * bukan sekadar kolom bernama lain. Dengan nama itu, `groups` memakai trait `BelongsToCompany` dan
 * kebijakan RLS yang sama persis dengan tabel lain, termasuk penjagaan "tidak boleh membuat data
 * untuk company lain" dan pencatatan percobaan akses lintas tenant. Satu kolom bernama khusus akan
 * memaksa lapisan isolasinya ditulis ulang di sini, dan lapisan isolasi yang ditulis dua kali adalah
 * lapisan isolasi yang suatu hari berbeda.
 *
 * Seluruh hasil konsolidasi (lihat migrasi berikutnya) adalah data milik entitas holding, dengan
 * `company_id` entitas holding dan kebijakan RLS biasa. Akibatnya yang penting: **konsolidator tidak
 * bisa membaca satu pun transaksi anak usaha**, karena baris-baris itu milik company lain dan
 * PostgreSQL yang menolaknya — bukan pemeriksaan di kode yang bisa terlupa di satu endpoint.
 *
 * Angka anak usaha sampai ke holding hanya lewat satu pintu: proses batch `runAsSystem` yang
 * membaca saldo tiap entitas lalu MENULISKAN ringkasannya ke tabel milik holding, lengkap dengan
 * nama entitasnya. Karena nama itu disalin, halaman konsolidasi tidak pernah perlu membaca tabel
 * `companies` lintas tenant sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('legal_name', 150)->nullable();
            /*
             * Entitas holding yang memegang hasil konsolidasi. Ia company biasa — punya bagan
             * akunnya sendiri, yang sekaligus menjadi bagan akun konsolidasi — dan ia pula pemilik
             * baris group ini menurut RLS, sehingga anak usaha tidak melihat keberadaan grupnya.
             */
            $table->foreignUuid('company_id')->constrained('companies');
            $table->char('currency', 3)->default('IDR');
            $table->string('notes', 300)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users');
            $table->timestampsTz();
            $table->softDeletesTz();

            /*
             * Satu entitas holding memegang paling banyak satu grup. Bukan batasan teknis melainkan
             * arti: kalau satu entitas memegang dua grup, "laporan konsolidasi entitas ini" tidak
             * lagi menunjuk satu laporan, dan setiap layar harus bertanya grup yang mana — pertanyaan
             * yang tidak pernah bisa dijawab pengguna karena ia tidak membuat grup kedua itu.
             */
            $table->unique('company_id');
        });
        Rls::enable('groups');

        Schema::table('companies', function (Blueprint $table): void {
            $table->uuid('group_id')->nullable()->comment('Keanggotaan grup konsolidasi (GRP-01)');
            $table->foreign('group_id')->references('id')->on('groups');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['group_id']);
            $table->dropIndex(['group_id']);
            $table->dropColumn('group_id');
        });
        Schema::dropIfExists('groups');
    }
};
