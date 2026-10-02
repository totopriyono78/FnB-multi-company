<?php

namespace App\Modules\Consolidation\Console;

use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\ConsolidationService;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Tarik ulang saldo seluruh entitas untuk periode berjalan tiap grup (CON-01).
 *
 * Dijadwalkan harian, dan itu bagian dari janji "LK tiap 2 hari": kertas kerja yang hanya diperbarui
 * saat seseorang ingat membuka layarnya akan selalu tertinggal dari buku entitasnya.
 *
 * Hanya menyentuh proses berstatus **draft**. Periode yang sudah dikunci final tidak pernah berubah
 * sendiri di belakang punggung orang yang menguncinya — kalau angkanya memang harus berubah, ia
 * dibuka kembali dengan alasan yang tercatat.
 */
class RunConsolidation extends Command
{
    protected $signature = 'konsolidasi:jalankan
        {--bulan= : Bulan periode dalam format YYYY-MM; bawaannya bulan ini}';

    protected $description = 'Menarik ulang saldo entitas untuk periode berjalan tiap grup konsolidasi';

    public function handle(TenantContext $context, ConsolidationService $service): int
    {
        $month = $this->month();
        if ($month === null) {
            $this->error('Format --bulan harus YYYY-MM, misalnya 2026-10.');

            return self::FAILURE;
        }

        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        /** @var list<array{id: string, company_id: string, name: string}> $groups */
        $groups = $context->runAsSystem(
            fn (): array => Group::query()->withoutGlobalScopes()
                ->orderBy('code')
                ->get(['id', 'company_id', 'name'])
                ->map(fn (Group $g): array => [
                    'id' => (string) $g->id,
                    'company_id' => (string) $g->company_id,
                    'name' => (string) $g->name,
                ])->all()
        );

        if ($groups === []) {
            $this->line('Belum ada grup konsolidasi.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($groups as $row) {
            $context->runAsTenant($row['company_id'], function () use ($row, $service, $from, $to, &$failed): void {
                $group = Group::query()->whereKey($row['id'])->first();
                if ($group === null) {
                    return;
                }
                try {
                    $run = $service->openRun($group, $from, $to, $from->translatedFormat('F Y'));
                    if (! $run->isDraft()) {
                        $this->line("{$row['name']}: {$run->number} sudah final, dilewati.");

                        return;
                    }
                    $run = $service->generate($run);
                    $this->info("{$row['name']}: {$run->number} — {$run->entity_count} entitas ditarik.");
                } catch (ConsolidationException $e) {
                    $this->error("{$row['name']}: ".$e->getMessage());
                    $failed = true;
                }
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function month(): ?CarbonImmutable
    {
        $raw = $this->option('bulan');
        if (! is_string($raw) || $raw === '') {
            return CarbonImmutable::now(config('app.display_timezone', 'Asia/Jakarta'))->startOfMonth();
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return null;
        }

        try {
            // Carbon dalam mode ketat MELEMPAR, bukan mengembalikan false, jadi ini harus dijaga.
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01');
        } catch (\Throwable) {
            return null;
        }

        return $parsed?->startOfMonth();
    }
}
