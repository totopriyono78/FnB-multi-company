<?php

use App\Modules\Shared\Infrastructure\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan retur untuk transaksi yang dibayar lewat payment gateway (keputusan user 30 Sep 2026).
 *
 * AINO tidak menyediakan API refund (lihat `claude/integrasi-pembayaran-qris-aino.md` §3 butir 8),
 * sehingga uang QRIS hanya bisa dikembalikan secara manual dari sisi acquirer. Sebelum ini POS
 * menerima retur bermetode QRIS, menandainya `gateway_refund_required`, lalu selesai — pembukuan
 * mencatat uang sudah kembali padahal tidak ada perintah pengembalian yang pernah dikirim ke
 * siapa pun. Selisih itu tidak akan pernah muncul dengan sendirinya: kas laci cocok, laporan cocok,
 * dan hanya pelanggan yang tahu uangnya tidak kembali.
 *
 * Tabel ini memisahkan **niat mengembalikan** dari **uang yang benar-benar kembali**:
 *
 * - Baris di sini TIDAK mengurangi `orders.refunded_total`, tidak mengubah status pesanan, tidak
 *   memindahkan stok, dan tidak muncul di laporan penjualan. Ia hanya daftar tugas.
 * - `refunds` baru lahir saat finance menyatakan dananya sudah dikembalikan lewat dashboard
 *   acquirer, dan saat itulah seluruh akibatnya berjalan seperti retur biasa.
 *
 * Urutan itu sengaja: mencatat retur sebelum uangnya kembali berarti membukukan pengeluaran yang
 * belum terjadi. Menundanya paling buruk membuat laporan tertinggal beberapa jam dari kenyataan.
 *
 * Tabel ini BOLEH di-UPDATE (status berpindah pending → settled/cancelled), karena itu ia tidak
 * ikut `orders`/`refunds` yang append-only dan berpartisi per hari bisnis — pola yang sama dengan
 * `open_bills`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_refund_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets');
            $table->uuid('order_id');
            $table->date('order_business_date');
            $table->date('business_date')->comment('Hari bisnis saat pengajuan dibuat');
            $table->uuid('shift_id')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('method', 20)->comment('Metode gateway asal: qris | ewallet');
            $table->jsonb('lines')->comment('[{order_item_id, qty, amount}] kosong = seluruh transaksi');
            $table->string('stock_action', 10)->comment('return | waste — dijalankan saat pengajuan diselesaikan');
            $table->string('reason', 300);
            $table->uuid('requested_by');
            $table->foreign('requested_by')->references('id')->on('users');
            $table->uuid('authorized_by')->nullable();
            $table->foreign('authorized_by')->references('id')->on('users');
            $table->string('status', 12)->default('pending')->comment('pending | settled | cancelled');
            // Nomor referensi pengembalian dari dashboard acquirer. Inilah satu-satunya bukti bahwa
            // uangnya benar-benar dikirim, jadi ia wajib diisi saat pengajuan diselesaikan.
            $table->string('gateway_reference', 64)->nullable();
            $table->uuid('refund_id')->nullable()->comment('Refund yang lahir saat pengajuan diselesaikan');
            $table->uuid('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users');
            $table->string('resolution_note', 300)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('device_created_at');
            $table->timestampTz('server_received_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Daftar tugas finance: yang pending, tertua dulu.
            $table->index(['company_id', 'status', 'device_created_at']);
            $table->index(['company_id', 'order_id']);
        });

        DB::statement('ALTER TABLE gateway_refund_requests ADD CONSTRAINT gateway_refund_requests_amount_check CHECK (amount > 0)');
        // Pengajuan yang sudah selesai wajib menyebut siapa yang menyelesaikan dan kapan; yang
        // diselesaikan sebagai "sudah dikembalikan" wajib membawa nomor referensi acquirer.
        DB::statement(<<<'SQL'
            ALTER TABLE gateway_refund_requests ADD CONSTRAINT gateway_refund_requests_resolution_check CHECK (
                (status = 'pending'   AND resolved_by IS NULL AND resolved_at IS NULL AND refund_id IS NULL AND gateway_reference IS NULL)
             OR (status = 'cancelled' AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND refund_id IS NULL)
             OR (status = 'settled'   AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND refund_id IS NOT NULL AND gateway_reference IS NOT NULL)
            )
        SQL);

        Rls::enable('gateway_refund_requests');
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_refund_requests');
    }
};
