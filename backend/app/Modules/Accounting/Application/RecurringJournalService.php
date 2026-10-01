<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\RecurringJournal;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menjalankan templat jurnal berulang (ACC-06).
 *
 * Jurnal yang dihasilkan lahir **draft** seperti jurnal otomatis lain: templat menghapus pekerjaan
 * mengetik, bukan pekerjaan memeriksa. Nilai sewa naik, kontrak berakhir, mobil dijual — semua
 * membuat templat yang kemarin benar menjadi salah hari ini, dan satu-satunya yang bisa menangkap
 * itu adalah orang yang membacanya sebelum diposting.
 */
class RecurringJournalService
{
    public const SOURCE = 'recurring';

    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    public static function sourceKey(string $templateId, CarbonImmutable $month): string
    {
        return self::SOURCE.':'.$templateId.':'.$month->format('Y-m');
    }

    /**
     * Buat jurnal untuk seluruh templat yang jatuh tempo sampai `$until`.
     *
     * Menengok ke belakang beberapa bulan, bukan hanya bulan berjalan: server yang mati sepekan,
     * entitas yang baru dipasang di tengah tahun, atau templat yang baru diaktifkan semuanya
     * meninggalkan bulan yang terlewat — dan bulan yang terlewat tidak akan pernah mengisi dirinya
     * sendiri.
     *
     * @return int jumlah jurnal baru yang dibuat
     */
    public function run(CarbonImmutable $until, User $actor, int $lookbackMonths = 3): int
    {
        $dibuat = 0;

        foreach (RecurringJournal::query()->where('is_active', true)->get() as $template) {
            for ($mundur = $lookbackMonths; $mundur >= 0; $mundur--) {
                $bulan = $until->startOfMonth()->subMonths($mundur);
                if (! $template->isDue($bulan)) {
                    continue;
                }
                $tanggal = $template->dateFor($bulan);
                if ($tanggal->greaterThan($until)) {
                    continue;
                }
                if ($this->existing($template, $bulan) !== null) {
                    continue;
                }

                try {
                    $this->create($template, $tanggal, $bulan, $actor);
                    $dibuat++;
                } catch (AccountingException $e) {
                    /*
                     * Satu templat yang bermasalah — akunnya dinonaktifkan, periodenya sudah
                     * ditutup permanen — tidak boleh menghentikan templat lain. Dicatat supaya
                     * terlihat, lalu dilanjutkan.
                     */
                    $this->audit->log('recurring_journal.failed', $template, new: [
                        'name' => $template->name, 'month' => $bulan->format('Y-m'),
                        'code' => $e->errorCode, 'message' => $e->getMessage(),
                    ], userId: $actor->id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $dibuat;
    }

    public function existing(RecurringJournal $template, CarbonImmutable $month): ?Journal
    {
        return Journal::query()->where('source_key', self::sourceKey($template->id, $month))->first();
    }

    private function create(RecurringJournal $template, CarbonImmutable $tanggal, CarbonImmutable $bulan, User $actor): Journal
    {
        return DB::transaction(function () use ($template, $tanggal, $bulan, $actor): Journal {
            $journal = $this->journals->create([
                'journal_date' => $tanggal->format('Y-m-d'),
                'description' => mb_substr($template->description.' — '.$bulan->translatedFormat('F Y'), 0, 300),
                'source' => self::SOURCE,
                'source_key' => self::sourceKey($template->id, $bulan),
                'lines' => $template->lines,
            ], $actor);

            $template->forceFill(['last_generated_on' => $tanggal->format('Y-m-d')])->save();

            $this->audit->log('recurring_journal.generated', $template, new: [
                'name' => $template->name, 'month' => $bulan->format('Y-m'), 'journal' => $journal->number,
            ], userId: $actor->id);

            return $journal;
        });
    }
}
