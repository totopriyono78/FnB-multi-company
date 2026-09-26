<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah meja per outlet (FR-POS, keputusan user 23 Sep 2026).
 *
 * Dipakai layar kasir untuk membuat tombol pintas nomor meja 1..N agar penulisan
 * nomor meja seragam tanpa perlu data induk meja. Data induk meja (dengan area dan
 * kapasitas) menyusul di POS-3 bersama fitur tahan/gabung bill; kolom ini tetap
 * berguna sebagai jumlah default saat meja dibuatkan nanti.
 *
 * 0 berarti outlet tidak memakai nomor meja (mis. gerai bawa pulang).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('table_count')->default(0)->after('order_mode');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('table_count');
        });
    }
};
