<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tambahkan akun **1220 PPN Masukan** ke bagan akun entitas yang sudah berjalan (TAX-02).
 *
 * Akun ini sudah masuk template, tetapi template hanya dipasang sekali — entitas yang bagan akunnya
 * sudah terpasang tidak akan pernah mendapatkannya sampai seseorang menekan "Pasang template
 * standar" lagi. Dan gejalanya muncul jauh di kemudian hari: faktur pembelian berfaktur pajak yang
 * pertama kali diterbitkan akan ditolak dengan "Akun 1220 tidak ada", mungkin berminggu-minggu
 * setelah rilis, pada orang yang tidak tahu apa hubungannya dengan rilis itu.
 *
 * Karena itu diisikan di sini, untuk semua entitas sekaligus. Idempoten: entitas yang sudah punya
 * 1220 dilewati, dan entitas yang belum punya bagan akun sama sekali tidak dibuatkan apa-apa —
 * mereka akan mendapatkannya dari template saat memasangnya nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            INSERT INTO accounts (id, company_id, code, name, type, normal_balance, parent_id,
                                  is_postable, is_active, is_system, created_at, updated_at)
            SELECT gen_random_uuid(), induk.company_id, '1220', 'PPN Masukan', 'asset', 'debit', induk.id,
                   true, true, true, now(), now()
            FROM accounts induk
            WHERE induk.code = '1200'
              AND NOT EXISTS (
                  SELECT 1 FROM accounts ada
                  WHERE ada.company_id = induk.company_id AND ada.code = '1220'
              )
        ");
    }

    public function down(): void
    {
        // Hanya akun yang belum pernah dipakai yang dilepas kembali: akun yang sudah bermutasi
        // tidak boleh hilang, karena jurnalnya akan kehilangan nama akunnya.
        DB::statement("
            DELETE FROM accounts
            WHERE code = '1220'
              AND NOT EXISTS (SELECT 1 FROM journal_lines jl WHERE jl.account_id = accounts.id)
        ");
    }
};
