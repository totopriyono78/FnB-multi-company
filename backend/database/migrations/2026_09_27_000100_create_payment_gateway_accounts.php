<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial merchant payment gateway per outlet (keputusan user 27 Sep 2026).
 *
 * Sampai sekarang driver gateway memakai satu set kredensial global dari config/payments.php.
 * Itu tidak cukup untuk platform multi-company: tiap badan usaha — sering tiap outlet — punya
 * merchant sendiri di gateway, dan uangnya harus masuk ke rekening merchant itu, bukan ke
 * rekening tenant lain. Salah alamat di sini berarti uang pelanggan masuk ke kas orang lain.
 *
 * Cakupan: baris dengan `outlet_id` terisi berlaku untuk outlet itu; baris dengan `outlet_id`
 * NULL berlaku untuk seluruh outlet company yang belum punya barisnya sendiri. Pola "warisan"
 * ini membuat klien satu badan usaha cukup mengisi sekali, tanpa menutup kemungkinan tiap
 * outlet punya merchant sendiri.
 *
 * `secret_key` disimpan terenkripsi (cast `encrypted`, kunci dari APP_KEY) dan tidak pernah
 * ditampilkan kembali di layar mana pun — hanya bisa diganti, tidak bisa dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // NULL = berlaku untuk seluruh company. Outlet dihapus → kredensialnya ikut hilang.
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->cascadeOnDelete();
            $table->string('provider', 30);
            // Lingkungan gateway. Untuk AINO ini menentukan base URL; sandbox-nya memakai rel
            // pembayaran sungguhan, jadi keduanya sama-sama memindahkan uang nyata.
            $table->string('environment', 20)->default('sandbox');
            $table->string('merchant_code', 100);
            $table->text('secret_key');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz(6);

            $table->index(['company_id', 'provider', 'outlet_id']);
        });

        // Satu kredensial aktif per (company, outlet, provider). NULLS NOT DISTINCT (PostgreSQL 15+)
        // dipakai supaya baris tingkat company — yang outlet_id-nya NULL — juga ikut terkunci unik;
        // tanpa itu UNIQUE biasa menganggap setiap NULL berbeda dan mengizinkan baris ganda.
        DB::statement('CREATE UNIQUE INDEX payment_gateway_accounts_scope_unique
            ON payment_gateway_accounts (company_id, outlet_id, provider) NULLS NOT DISTINCT');

        DB::statement("ALTER TABLE payment_gateway_accounts
            ADD CONSTRAINT payment_gateway_accounts_environment_check
            CHECK (environment IN ('sandbox','production'))");

        Rls::enable('payment_gateway_accounts');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
