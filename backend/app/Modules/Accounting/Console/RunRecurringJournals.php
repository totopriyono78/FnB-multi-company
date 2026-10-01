<?php

namespace App\Modules\Accounting\Console;

use App\Modules\Accounting\Application\RecurringJournalService;
use App\Modules\Accounting\Domain\Models\RecurringJournal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Menjalankan templat jurnal berulang untuk seluruh entitas (ACC-06). Dijadwalkan tiap hari.
 *
 * Dijalankan harian, bukan bulanan, karena templat bisa dibuat kapan saja dan bulan yang terlewat
 * harus bisa menyusul sendiri tanpa menunggu pergantian bulan berikutnya.
 */
class RunRecurringJournals extends Command
{
    protected $signature = 'akuntansi:jurnal-berulang
        {--until= : Anggap hari ini tanggal tersebut (YYYY-MM-DD)}
        {--company= : Batasi ke satu company}
        {--months=3 : Berapa bulan ke belakang ikut diperiksa}';

    protected $description = 'Buat jurnal draft dari templat jurnal berulang yang sudah jatuh tempo';

    public function handle(TenantContext $context, RecurringJournalService $service): int
    {
        $until = $this->option('until') !== null
            ? CarbonImmutable::parse((string) $this->option('until'))->startOfDay()
            : CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

        $companies = $this->option('company') !== null
            ? [(string) $this->option('company')]
            : $context->runAsSystem(fn () => Company::query()->pluck('id')->all());

        $total = 0;
        foreach ($companies as $companyId) {
            $context->runAsTenant((string) $companyId, function () use ($service, $until, &$total): void {
                /*
                 * Pelakunya adalah pembuat templat itu sendiri. Jurnal harus bisa dipertanggungjawabkan
                 * kepada seseorang, dan orang yang paling masuk akal adalah yang menyatakan templat ini
                 * benar. Ia tetap tidak bisa memposting jurnalnya sendiri — maker–checker tetap berlaku.
                 */
                $pembuat = RecurringJournal::query()->where('is_active', true)->value('created_by');
                if ($pembuat === null) {
                    return;
                }
                $actor = User::query()->find((string) $pembuat);
                if ($actor === null) {
                    return;
                }

                $total += $service->run($until, $actor, max(0, (int) $this->option('months')));
            });
        }

        $this->info("Jurnal berulang dibuat: {$total}.");

        return self::SUCCESS;
    }
}
