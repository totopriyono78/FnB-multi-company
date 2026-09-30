<?php

namespace App\Modules\Accounting\Console;

use App\Modules\Accounting\Application\SalesJournal;
use App\Modules\Accounting\Listeners\PostSalesJournal;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyusun jurnal penjualan untuk hari bisnis yang sudah ditutup tetapi belum punya jurnal.
 *
 * Menutupi tiga keadaan sekaligus, sehingga tidak perlu tabel status tersendiri: hari yang ditutup
 * sebelum modul akuntansi ada, hari yang penyusunan jurnalnya gagal, dan hari yang gagal karena
 * pemetaan akun belum lengkap lalu dilengkapi kemudian. Pertanyaan "hari ini sudah dijurnal belum?"
 * dijawab oleh keberadaan jurnalnya sendiri — satu-satunya sumber yang tidak bisa berbohong.
 */
class PostSalesJournals extends Command
{
    protected $signature = 'akuntansi:jurnal-penjualan
        {--days=31 : Mundur sekian hari dari hari ini bila --from tidak diberikan}
        {--from= : Tanggal awal (YYYY-MM-DD)}
        {--to= : Tanggal akhir (YYYY-MM-DD)}
        {--company= : Batasi ke satu company}
        {--outlet= : Batasi ke satu outlet}';

    protected $description = 'Susun jurnal penjualan untuk hari bisnis tertutup yang belum dijurnal';

    public function handle(TenantContext $context, PostSalesJournal $poster): int
    {
        $to = $this->option('to') !== null ? CarbonImmutable::parse((string) $this->option('to')) : CarbonImmutable::today();
        $from = $this->option('from') !== null
            ? CarbonImmutable::parse((string) $this->option('from'))
            : $to->subDays(max(0, (int) $this->option('days')));
        if ($from->greaterThan($to)) {
            $this->error('Tanggal awal melewati tanggal akhir.');

            return self::FAILURE;
        }

        $companies = $this->option('company') !== null
            ? [(string) $this->option('company')]
            : $context->runAsSystem(fn () => Company::query()->pluck('id')->all());

        $made = 0;
        $seen = 0;

        foreach ($companies as $companyId) {
            $context->runAsTenant((string) $companyId, function () use ($poster, $companyId, $from, $to, &$made, &$seen): void {
                $days = DB::table('business_days as bd')
                    // RLS sudah membatasi journals ke company ini, jadi kuncinya cukup dicocokkan apa adanya.
                    ->leftJoin('journals as j', 'j.source_key', '=', DB::raw("('".SalesJournal::SOURCE.":' || bd.outlet_id || ':' || to_char(bd.business_date, 'YYYY-MM-DD'))"))
                    ->where('bd.company_id', $companyId)
                    ->whereBetween('bd.business_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                    ->when($this->option('outlet') !== null, fn ($q) => $q->where('bd.outlet_id', (string) $this->option('outlet')))
                    ->whereNull('j.id')
                    ->orderBy('bd.business_date')
                    ->get(['bd.outlet_id', 'bd.business_date']);

                foreach ($days as $day) {
                    $seen++;
                    if ($poster->post((string) $companyId, (string) $day->outlet_id, CarbonImmutable::parse($day->business_date)->format('Y-m-d'))) {
                        $made++;
                    }
                }
            });
        }

        $this->info("Hari tanpa jurnal: {$seen}. Jurnal baru dibuat: {$made}.");

        return self::SUCCESS;
    }
}
