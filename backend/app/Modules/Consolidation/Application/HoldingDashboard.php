<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Consolidation\Domain\Models\ConsolidationEntity;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportTable;

/**
 * Dasbor holding: kesiapan tutup buku tiap entitas (CON-09).
 *
 * Angkanya dibaca dari **snapshot proses konsolidasi terakhir**, bukan dibaca ulang langsung dari
 * buku tiap entitas saat halaman dibuka. Itu pilihan yang disengaja: membaca langsung berarti
 * halaman ini harus berjalan lintas tenant setiap kali dilihat, dan satu-satunya momen lintas tenant
 * di seluruh modul ini sebaiknya tetap satu — proses batch yang bisa diuji dan dicatat.
 *
 * Harganya: angkanya seumur snapshot terakhir. Itu dikatakan apa adanya di kolom filter ("Ditarik"),
 * bukan disembunyikan — dasbor yang mengaku langsung padahal tidak jauh lebih berbahaya daripada
 * dasbor yang menyebutkan umur datanya.
 */
class HoldingDashboard
{
    public const READY = 'Siap';

    public function build(?ConsolidationRun $run): ReportTable
    {
        $entities = $run === null ? [] : ConsolidationEntity::query()
            ->where('run_id', $run->id)->orderBy('sequence')->get()->all();

        $rows = [];
        $siap = 0;
        foreach ($entities as $entity) {
            $status = $this->readiness($entity);
            if ($status === self::READY) {
                $siap++;
            }
            $rows[] = [
                'entity' => $entity->source_code.' — '.$entity->source_name,
                'posted' => (string) $entity->posted_journal_count,
                'draft' => (string) $entity->draft_journal_count,
                'last' => $entity->last_journal_date?->translatedFormat('d M Y') ?? '—',
                'status' => $status,
                '_style' => $status === self::READY ? 'item' : 'subtotal',
            ];
        }

        return new ReportTable(
            key: 'konsolidasi-dasbor',
            title: 'Dasbor Holding — Kesiapan Tutup Buku',
            subtitle: $run?->describe(),
            columns: [
                'entity' => ['label' => 'Entitas', 'type' => ReportTable::TEXT],
                'posted' => ['label' => 'Jurnal terposting', 'type' => ReportTable::NUMBER],
                'draft' => ['label' => 'Belum diposting', 'type' => ReportTable::NUMBER],
                'last' => ['label' => 'Jurnal terakhir', 'type' => ReportTable::TEXT],
                'status' => ['label' => 'Kesiapan', 'type' => ReportTable::TEXT],
            ],
            rows: $rows,
            totals: null,
            filters: array_filter([
                'Grup' => $run?->group?->name,
                'Periode' => $run?->periodLabel(),
                'Ditarik' => $run?->generated_at?->timezone(ReportAccess::timezone())->translatedFormat('d M Y H:i'),
            ], fn (?string $value): bool => $value !== null && $value !== ''),
            summary: [
                ['label' => 'Entitas', 'value' => (string) count($entities), 'type' => ReportTable::NUMBER],
                ['label' => 'Siap', 'value' => (string) $siap, 'type' => ReportTable::NUMBER],
                ['label' => 'Perlu tindakan', 'value' => (string) (count($entities) - $siap), 'type' => ReportTable::NUMBER],
            ],
            notes: array_values(array_filter([
                $run === null
                    ? 'Belum ada proses konsolidasi. Buat satu untuk periode yang ingin dilihat, lalu tarik saldo entitas.'
                    : null,
                $run !== null && $run->generated_at === null
                    ? 'Proses konsolidasi ini belum pernah ditarik saldonya, jadi tabel di atas masih kosong.'
                    : null,
                'Angka di atas adalah keadaan saat saldo terakhir ditarik, bukan saat halaman ini dibuka. '
                    .'Tarik ulang bila entitas baru saja memposting jurnal.',
                '"Belum ada data" pada entitas yang bukunya diisi manual (villa, retail) berarti '
                    .'jurnalnya memang belum dientri — bukan berarti tidak ada kegiatan di sana.',
            ])),
        );
    }

    private function readiness(ConsolidationEntity $entity): string
    {
        if (! $entity->hasData()) {
            return 'Belum ada data';
        }
        if ($entity->out_of_balance) {
            return 'Buku tidak seimbang';
        }
        if ($entity->draft_journal_count > 0) {
            return $entity->draft_journal_count.' jurnal belum diposting';
        }

        return self::READY;
    }
}
