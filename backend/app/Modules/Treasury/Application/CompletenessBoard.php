<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Sales\Domain\Models\BusinessDay;
use App\Modules\Treasury\Domain\Models\BankStatement;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Carbon\CarbonImmutable;

/**
 * Papan kelengkapan entry (FIN-07).
 *
 * Inilah yang membuat janji "laporan keuangan tiap dua hari" bisa ditepati — atau, kalau tidak bisa,
 * membuat alasannya terlihat sebelum tenggatnya lewat. Satu baris per hari, dan tiap kolom menjawab
 * satu pertanyaan yang kalau jawabannya "belum", laporan hari itu belum boleh dipercaya:
 *
 * | Kolom | Pertanyaan |
 * |---|---|
 * | Tutup hari | Semua outlet sudah menutup harinya? |
 * | Jurnal penjualan | Penjualan hari itu sudah jadi jurnal? |
 * | Jurnal diposting | Tidak ada jurnal yang masih menggantung sebagai draft/diajukan? |
 * | Dokumen | Tidak ada SPPK yang menunggu tanda tangan? |
 * | Faktur | Tidak ada faktur pembelian yang masih draft? |
 * | Bank | Tanggal itu sudah tercakup rekonsiliasi bank yang terkunci? |
 *
 * Papan ini sengaja tidak memberi nilai atau persentase. Angka yang dirata-rata membuat hari yang
 * bolong terlihat kecil; daftar yang menyebut harinya membuat orang membukanya.
 */
class CompletenessBoard
{
    public const DONE = '✓';

    public const PENDING = '—';

    /** Hari yang tidak ada transaksinya sama sekali, atau belum dijalani. */
    public const NOT_APPLICABLE = '·';

    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportTable
    {
        $hari = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $hari[$d->format('Y-m-d')] = true;
        }

        $dibuka = $this->openedBusinessDays($from, $to);
        $tutup = $this->closedBusinessDays($from, $to);
        /*
         * Dibandingkan sebagai TEKS 'Y-m-d', bukan sebagai objek tanggal. Tanggal baris dibentuk dari
         * teks tanpa zona (UTC), sedangkan "hari ini" dibentuk di zona entitas — tengah malam UTC
         * hari ini lebih LAMBAT daripada tengah malam Jakarta hari ini, sehingga perbandingan objek
         * menyatakan hari ini "belum dijalani" setiap hari, sepanjang hari.
         */
        $hariIni = CarbonImmutable::now(config('app.display_timezone'))->format('Y-m-d');
        $jurnalPenjualan = $this->salesJournalDates($from, $to);
        $menggantung = $this->unpostedJournalDates($from, $to);
        $dokumen = $this->pendingDocumentDates($from, $to);
        $faktur = $this->draftInvoiceDates($from, $to);
        $bank = $this->reconciledDates($from, $to);

        $rows = [];
        $bolong = 0;
        $diperiksa = 0;
        foreach (array_keys($hari) as $tanggal) {
            $tanggalnya = CarbonImmutable::parse($tanggal);
            /*
             * Hari yang belum dijalani tidak diperiksa sama sekali. Tanpa ini, memilih rentang satu
             * bulan penuh di tanggal 2 akan melaporkan 29 hari "belum lengkap" — papan yang berteriak
             * setiap hari berhenti dibaca dalam sepekan, dan itu kegagalan yang lebih buruk daripada
             * tidak punya papan.
             */
            $belumDijalani = $tanggal > $hariIni;

            $jumlahDibuka = $dibuka[$tanggal] ?? 0;
            $jumlahDitutup = $tutup[$tanggal] ?? 0;
            /*
             * Begitu pula hari yang memang tidak ada transaksinya: outlet tutup, libur, belum buka
             * cabang. Tidak ada yang perlu ditutup dan tidak ada yang perlu dijurnal, jadi menyebutnya
             * "belum lengkap" sama saja dengan menuntut orang menjelaskan hari libur.
             */
            $tanpaTransaksi = ! $belumDijalani && $jumlahDibuka === 0;

            $bersihJurnal = ! isset($menggantung[$tanggal]);
            $bersihDokumen = ! isset($dokumen[$tanggal]);
            $bersihFaktur = ! isset($faktur[$tanggal]);
            $terekonsiliasi = isset($bank[$tanggal]);

            if ($belumDijalani) {
                $rows[] = $this->row($tanggalnya, self::NOT_APPLICABLE, self::NOT_APPLICABLE,
                    self::NOT_APPLICABLE, self::NOT_APPLICABLE, self::NOT_APPLICABLE, self::NOT_APPLICABLE, null);

                continue;
            }

            $tutupHari = $jumlahDibuka > 0 && $jumlahDitutup >= $jumlahDibuka;
            $adaJurnal = isset($jurnalPenjualan[$tanggal]);
            // Jurnal penjualan hanya dituntut bila memang ada hari bisnis yang sudah ditutup.
            $jurnalCukup = $jumlahDitutup === 0 || $adaJurnal;

            $diperiksa++;
            $lengkap = ($tanpaTransaksi || $tutupHari) && $jurnalCukup
                && $bersihJurnal && $bersihDokumen && $bersihFaktur;
            if (! $lengkap) {
                $bolong++;
            }

            $rows[] = $this->row(
                $tanggalnya,
                $tanpaTransaksi ? self::NOT_APPLICABLE : $jumlahDitutup.'/'.$jumlahDibuka,
                $jumlahDitutup === 0 ? self::NOT_APPLICABLE : ($adaJurnal ? self::DONE : self::PENDING),
                $bersihJurnal ? self::DONE : ($menggantung[$tanggal].' menggantung'),
                $bersihDokumen ? self::DONE : ($dokumen[$tanggal].' menunggu'),
                $bersihFaktur ? self::DONE : ($faktur[$tanggal].' draft'),
                $terekonsiliasi ? self::DONE : self::PENDING,
                $lengkap ? null : 'item',
            );
        }

        return new ReportTable(
            key: 'papan-kelengkapan',
            title: 'Papan Kelengkapan Entry',
            subtitle: $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            columns: [
                'date' => ['label' => 'Tanggal', 'type' => ReportTable::TEXT],
                'closed' => ['label' => 'Tutup hari', 'type' => ReportTable::TEXT],
                'sales_journal' => ['label' => 'Jurnal penjualan', 'type' => ReportTable::TEXT],
                'journals_posted' => ['label' => 'Jurnal diposting', 'type' => ReportTable::TEXT],
                'documents' => ['label' => 'Dokumen', 'type' => ReportTable::TEXT],
                'invoices' => ['label' => 'Faktur', 'type' => ReportTable::TEXT],
                'bank' => ['label' => 'Rekonsiliasi bank', 'type' => ReportTable::TEXT],
            ],
            rows: $rows,
            summary: [
                ['label' => 'Hari diperiksa', 'value' => (string) $diperiksa, 'type' => ReportTable::NUMBER],
                ['label' => 'Hari belum lengkap', 'value' => (string) $bolong, 'type' => ReportTable::NUMBER],
            ],
            notes: [
                'Tanda "'.self::NOT_APPLICABLE.'" berarti tidak berlaku: hari yang belum dijalani, atau hari yang '
                .'tidak ada transaksinya sama sekali. Hari seperti itu tidak dihitung sebagai belum lengkap.',
                'Kolom rekonsiliasi bank menandai hari yang sudah tercakup periode rekonsiliasi yang DIKUNCI. '
                .'Rekonsiliasi yang belum dikunci belum menyatakan apa pun.',
                'Hari yang belum lengkap bukan berarti angkanya salah — ia berarti angkanya belum tentu lengkap, '
                .'dan laporan keuangan yang disusun dari hari seperti itu bisa berubah besok.',
            ],
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function row(
        CarbonImmutable $tanggal,
        string $closed,
        string $salesJournal,
        string $journalsPosted,
        string $documents,
        string $invoices,
        string $bank,
        ?string $style,
    ): array {
        return [
            'date' => $tanggal->translatedFormat('D, d M Y'),
            'closed' => $closed,
            'sales_journal' => $salesJournal,
            'journals_posted' => $journalsPosted,
            'documents' => $documents,
            'invoices' => $invoices,
            'bank' => $bank,
            '_style' => $style,
        ];
    }

    /** @return array<string, int> tanggal => jumlah outlet yang hari bisnisnya dibuka */
    private function openedBusinessDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $out */
        $out = BusinessDay::query()
            ->whereBetween('business_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('business_date, count(distinct outlet_id) as jumlah')
            ->groupBy('business_date')
            ->pluck('jumlah', 'business_date')
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::parse((string) $k)->format('Y-m-d') => (int) $v])
            ->all();

        return $out;
    }

    /** @return array<string, int> tanggal => jumlah outlet yang sudah tutup hari */
    private function closedBusinessDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $out */
        $out = BusinessDay::query()
            ->whereNotNull('closed_at')
            ->whereBetween('business_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('business_date, count(distinct outlet_id) as jumlah')
            ->groupBy('business_date')
            ->pluck('jumlah', 'business_date')
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::parse((string) $k)->format('Y-m-d') => (int) $v])
            ->all();

        return $out;
    }

    /** @return array<string, true> */
    private function salesJournalDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (Journal::query()->where('source', 'sales')
            ->whereBetween('journal_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->pluck('journal_date') as $tanggal) {
            $out[CarbonImmutable::parse((string) $tanggal)->format('Y-m-d')] = true;
        }

        return $out;
    }

    /** @return array<string, int> tanggal => jumlah jurnal yang belum diposting */
    private function unpostedJournalDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $out */
        $out = Journal::query()
            ->whereIn('status', [Journal::DRAFT, Journal::SUBMITTED])
            ->whereBetween('journal_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('journal_date, count(*) as jumlah')
            ->groupBy('journal_date')
            ->pluck('jumlah', 'journal_date')
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::parse((string) $k)->format('Y-m-d') => (int) $v])
            ->all();

        return $out;
    }

    /** @return array<string, int> */
    private function pendingDocumentDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $out */
        $out = PaymentRequest::query()
            ->where('status', PaymentRequest::SUBMITTED)
            ->whereBetween('request_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('request_date, count(*) as jumlah')
            ->groupBy('request_date')
            ->pluck('jumlah', 'request_date')
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::parse((string) $k)->format('Y-m-d') => (int) $v])
            ->all();

        return $out;
    }

    /** @return array<string, int> */
    private function draftInvoiceDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<string, int> $out */
        $out = PurchaseInvoice::query()
            ->where('status', PurchaseInvoice::DRAFT)
            ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->selectRaw('invoice_date, count(*) as jumlah')
            ->groupBy('invoice_date')
            ->pluck('jumlah', 'invoice_date')
            ->mapWithKeys(fn ($v, $k) => [CarbonImmutable::parse((string) $k)->format('Y-m-d') => (int) $v])
            ->all();

        return $out;
    }

    /**
     * Tanggal yang sudah tercakup rekonsiliasi bank TERKUNCI.
     *
     * @return array<string, true>
     */
    private function reconciledDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (BankStatement::query()->whereNotNull('locked_at')
            ->where('period_end', '>=', $from->format('Y-m-d'))
            ->where('period_start', '<=', $to->format('Y-m-d'))
            ->get(['period_start', 'period_end']) as $statement) {
            for ($d = $statement->period_start; $d->lessThanOrEqualTo($statement->period_end); $d = $d->addDay()) {
                $out[$d->format('Y-m-d')] = true;
            }
        }

        return $out;
    }

    /** Dipakai paket LK terjadwal untuk memberi peringatan sebelum laporan dikirim. */
    public function incompleteDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $tabel = $this->build($from, $to);
        foreach ($tabel->summary as $ringkas) {
            if ($ringkas['label'] === 'Hari belum lengkap') {
                return (int) $ringkas['value'];
            }
        }

        return 0;
    }
}
