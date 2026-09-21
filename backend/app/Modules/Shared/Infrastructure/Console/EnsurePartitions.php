<?php

namespace App\Modules\Shared\Infrastructure\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Membuat partisi bulanan untuk tabel yang dipartisi (NFR-SCL-04). Jadwalkan harian. */
class EnsurePartitions extends Command
{
    protected $signature = 'fnb:partitions {--months=3 : Jumlah bulan ke depan yang disiapkan (ditambah bulan lalu)}';

    protected $description = 'Menyiapkan partisi bulanan tabel audit log & transaksi';

    /**
     * Tabel berpartisi bulanan => [kolom partisi, jenis kolom].
     *
     * @var array<string, array{0: string, 1: 'timestamp'|'date'}>
     */
    private const TABLES = [
        'audit_logs' => ['created_at', 'timestamp'],
        'orders' => ['business_date', 'date'],
        'order_items' => ['business_date', 'date'],
        'payments' => ['business_date', 'date'],
    ];

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return self::SUCCESS;
        }

        $months = max(1, (int) $this->option('months'));
        // Mulai dari bulan lalu agar transaksi offline yang terlambat masuk tetap punya partisi.
        $start = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();

        foreach (self::TABLES as $table => [$column, $kind]) {
            if (DB::selectOne('SELECT to_regclass(?) AS oid', [$table])->oid === null) {
                continue; // tabel belum dibuat (migrasi berikutnya)
            }
            $bound = fn (CarbonImmutable $d) => $kind === 'date' ? $d->format('Y-m-d') : $d->toIso8601String();
            for ($i = 0; $i <= $months; $i++) {
                $from = $start->addMonths($i);
                $to = $from->addMonth();
                $name = sprintf('%s_%s', $table, $from->format('Y_m'));

                $exists = DB::selectOne('SELECT to_regclass(?) AS oid', [$name])->oid;
                if ($exists !== null) {
                    continue;
                }

                // Baris yang sudah jatuh ke partisi default untuk rentang ini harus dipindah dulu.
                $hasDefaultRows = DB::selectOne(
                    "SELECT EXISTS (SELECT 1 FROM {$table}_default WHERE {$column} >= ? AND {$column} < ?) AS e",
                    [$bound($from), $bound($to)]
                )->e;

                if ($hasDefaultRows) {
                    $this->warn("Lewati {$name}: partisi default berisi data pada rentang ini.");

                    continue;
                }

                DB::statement(sprintf(
                    "CREATE TABLE %s PARTITION OF %s FOR VALUES FROM ('%s') TO ('%s')",
                    $name,
                    $table,
                    $bound($from),
                    $bound($to),
                ));
                $this->line("Partisi dibuat: {$name}");
            }
        }

        return self::SUCCESS;
    }
}
