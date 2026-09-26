<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua kebutuhan resto ikan bakar (keputusan user 23 Sep 2026):
 *
 * 1. **Barang dijual per berat.** Ikan dipilih lalu ditimbang; harga = harga satuan x berat.
 *    Mesin harga sudah menerima jumlah pecahan (3 desimal), jadi yang kurang hanya penanda
 *    pada menu: `sold_by_weight` dan satuan yang ditampilkan (`unit`).
 *
 * 2. **Parkir bill.** Tamu makan dulu, membayar belakangan. Tagihan yang belum dibayar
 *    tidak boleh disimpan di `orders` karena tabel itu append-only dan berpartisi per hari
 *    bisnis; tagihan terbuka masih berubah-ubah. Karena itu dibuat tabel tersendiri yang
 *    boleh di-UPDATE, lalu "menjadi" transaksi resmi saat dibayar (`order_id` terisi).
 *
 * Tagihan terbuka milik OUTLET, bukan milik perangkat: kasir di perangkat mana pun dalam
 * outlet yang sama harus bisa membukanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->boolean('sold_by_weight')->default(false)->after('base_price');
            $table->string('unit', 10)->default('pcs')->after('sold_by_weight');
        });

        Schema::create('open_bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->uuid('device_id')->nullable();
            $table->foreign('device_id')->references('id')->on('devices');
            $table->uuid('order_id')->nullable();
            $table->string('label', 40)->nullable();
            $table->string('table_label', 30)->nullable();
            $table->string('customer_name', 80)->nullable();
            $table->string('note', 300)->nullable();
            $table->unsignedInteger('queue_no')->nullable();
            $table->string('channel_code', 30);
            $table->jsonb('lines');
            $table->jsonb('totals')->nullable();
            $table->date('business_date');
            $table->uuid('opened_by');
            $table->foreign('opened_by')->references('id')->on('users');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['company_id', 'outlet_id', 'closed_at']);
            $table->index(['company_id', 'business_date']);
        });

        Rls::enable('open_bills');
    }

    public function down(): void
    {
        Schema::dropIfExists('open_bills');
        Schema::table('items', function (Blueprint $table): void {
            $table->dropColumn(['sold_by_weight', 'unit']);
        });
    }
};
