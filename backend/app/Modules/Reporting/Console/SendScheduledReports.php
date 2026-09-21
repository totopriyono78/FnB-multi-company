<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Application\ReportScheduler;
use Illuminate\Console\Command;

/** Mengirim laporan terjadwal yang jatuh tempo (FR-RPT-08). Dijadwalkan tiap 5 menit. */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Kirim laporan terjadwal yang sudah jatuh tempo ke email penerima';

    public function handle(ReportScheduler $scheduler): int
    {
        $result = $scheduler->runDue();
        $this->info("Terkirim {$result['sent']}, gagal {$result['failed']}, dilewati {$result['skipped']}.");

        return self::SUCCESS;
    }
}
