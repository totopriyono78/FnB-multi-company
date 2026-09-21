<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan user 15 Sep 2026: pembulatan default ke Rp100 terdekat (tetap bisa diubah per outlet).
 * Outlet yang sudah ada tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outlets ALTER COLUMN rounding_unit SET DEFAULT 100');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE outlets ALTER COLUMN rounding_unit SET DEFAULT 0');
    }
};
