<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Reporting\Application\ReportTable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan Arus Kas (FIN-03) — metode **langsung**, disusun dari mutasi kas yang sebenarnya.
 *
 * ## Kenapa metode langsung, bukan tidak langsung
 *
 * Metode tidak langsung dimulai dari laba bersih lalu menyesuaikannya dengan perubahan modal kerja.
 * Ia benar, tetapi tidak menjawab pertanyaan yang sebenarnya diajukan pemilik usaha F&B: *uangnya
 * ke mana?* Metode langsung menjawab itu apa adanya, karena tiap barisnya adalah pengeluaran atau
 * penerimaan yang benar-benar terjadi.
 *
 * ## Bagaimana angkanya disusun
 *
 * "Kas" adalah seluruh akun daun di bawah **1100 Kas & Setara Kas** — definisi akuntansi yang baku,
 * dan sengaja TIDAK bergantung pada master rekening kas/bank: entitas yang belum mendaftarkan
 * rekeningnya tetap mendapat laporan arus kas yang benar.
 *
 * Untuk tiap jurnal yang menyentuh kas, arus kasnya dipecah ke **baris non-kas** di jurnal itu.
 * Caranya persis, bukan proporsi: karena tiap jurnal seimbang,
 *
 *     Σ(debit − kredit) baris kas  =  −Σ(debit − kredit) baris non-kas
 *
 * sehingga sumbangan tiap baris non-kas terhadap arus kas adalah **(kredit − debit)** baris itu
 * sendiri. Penjualan tunai (Dr Kas / Cr Pendapatan) menyumbang +pendapatan ke arus kas operasi;
 * pembelian aset (Dr Peralatan / Cr Bank) menyumbang −peralatan ke arus kas investasi.
 *
 * Transfer antar rekening kas otomatis bernilai nol: kedua barisnya kas, tidak ada baris non-kas,
 * jadi tidak ada yang disumbangkan ke bagian mana pun. Itu benar — memindahkan uang dari laci ke
 * bank bukan arus kas, hanya perpindahan tempat.
 *
 * ## Penggolongannya
 *
 * | Lawan kasnya | Masuk ke |
 * |---|---|
 * | Ekuitas, atau liabilitas jangka panjang (kode 23xx) | Pendanaan |
 * | Aset tetap (kode 15xx) | Investasi |
 * | Selain itu | Operasi |
 *
 * Penggolongan berdasarkan KODE untuk dua kelompok di atas, bukan jenis akun saja, karena "aset
 * tetap" dan "liabilitas jangka panjang" adalah pembagian di dalam jenis — dan bagan akun bawaan
 * sudah menyusunnya di 1500 dan 2300.
 */
class CashFlowStatement
{
    public const OPERATING = 'operasi';

    public const INVESTING = 'investasi';

    public const FINANCING = 'pendanaan';

    public const SECTION_LABEL = [
        self::OPERATING => 'ARUS KAS DARI AKTIVITAS OPERASI',
        self::INVESTING => 'ARUS KAS DARI AKTIVITAS INVESTASI',
        self::FINANCING => 'ARUS KAS DARI AKTIVITAS PENDANAAN',
    ];

    /** Induk akun kas & setara kas di bagan akun bawaan. */
    public const CASH_PARENT = '1100';

    public const FIXED_ASSET_PREFIX = '15';

    public const LONG_TERM_LIABILITY_PREFIX = '23';

    public function build(CarbonImmutable $from, CarbonImmutable $to, ?string $outletId = null): ReportTable
    {
        $kas = $this->cashAccountIds();
        if ($kas === []) {
            return $this->emptyTable($from, $to,
                'Bagan akun belum memuat kelompok Kas & Setara Kas (1100), jadi arus kas belum dapat disusun.');
        }

        $awal = $this->cashBalance($kas, $from->subDay(), $outletId);
        $akhir = $this->cashBalance($kas, $to, $outletId);

        $buckets = $this->movements($kas, $from, $to, $outletId);

        $rows = [];
        $jumlah = [];
        foreach ([self::OPERATING, self::INVESTING, self::FINANCING] as $bagian) {
            $rows[] = ['name' => self::SECTION_LABEL[$bagian], 'amount' => null, '_style' => 'section'];
            $isi = $buckets[$bagian] ?? [];
            uasort($isi, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

            $sub = BigDecimal::zero();
            foreach ($isi as $baris) {
                if ($baris['amount']->isZero()) {
                    continue;
                }
                $rows[] = ['name' => $baris['code'].' — '.$baris['name'],
                    'amount' => (string) $baris['amount']->toScale(2), '_style' => 'item'];
                $sub = $sub->plus($baris['amount']);
            }
            if ($isi === []) {
                $rows[] = ['name' => 'Tidak ada mutasi', 'amount' => '0.00', '_style' => 'item'];
            }
            $rows[] = ['name' => 'Arus kas bersih dari aktivitas '.$bagian,
                'amount' => (string) $sub->toScale(2), '_style' => 'subtotal'];
            $jumlah[$bagian] = $sub;
        }

        $bersih = $jumlah[self::OPERATING]->plus($jumlah[self::INVESTING])->plus($jumlah[self::FINANCING]);
        $rows[] = ['name' => 'KENAIKAN (PENURUNAN) KAS BERSIH', 'amount' => (string) $bersih->toScale(2), '_style' => 'result'];
        $rows[] = ['name' => 'Kas & setara kas awal periode', 'amount' => (string) $awal->toScale(2), '_style' => 'item'];
        $rows[] = ['name' => 'KAS & SETARA KAS AKHIR PERIODE', 'amount' => (string) $awal->plus($bersih)->toScale(2), '_style' => 'result'];

        /*
         * Pemeriksaan diri: kas akhir hasil penjumlahan harus sama dengan saldo kas yang benar-benar
         * ada di buku besar pada tanggal itu. Kalau berbeda, laporannya sendiri yang mengatakannya —
         * laporan arus kas yang diam-diam tidak menutup adalah laporan yang menyesatkan.
         */
        $selisih = $akhir->minus($awal->plus($bersih));

        return new ReportTable(
            key: 'arus-kas',
            title: 'Laporan Arus Kas',
            subtitle: $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            columns: [
                'name' => ['label' => 'Keterangan', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Nilai', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            summary: [
                ['label' => 'Operasi', 'value' => (string) $jumlah[self::OPERATING]->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Investasi', 'value' => (string) $jumlah[self::INVESTING]->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Pendanaan', 'value' => (string) $jumlah[self::FINANCING]->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Kas akhir', 'value' => (string) $akhir->toScale(2), 'type' => ReportTable::MONEY],
            ],
            notes: array_values(array_filter([
                'Disusun dengan metode langsung dari mutasi kas yang benar-benar terjadi; hanya jurnal yang sudah diposting yang dihitung.',
                'Transfer antar rekening kas tidak muncul di sini — memindahkan uang dari laci ke bank bukan arus kas, hanya perpindahan tempat.',
                $selisih->isZero() ? null
                    : 'PERINGATAN: kas akhir hasil penjumlahan berbeda '.ReportTable::rupiah((string) $selisih->toScale(2))
                        .' dari saldo kas di buku besar. Laporkan temuan ini.',
            ])),
        );
    }

    /**
     * Mutasi kas periode ini, dikelompokkan per bagian lalu per akun lawan.
     *
     * @param  list<string>  $cashIds
     * @return array<string, array<string, array{code: string, name: string, amount: BigDecimal}>>
     */
    private function movements(array $cashIds, CarbonImmutable $from, CarbonImmutable $to, ?string $outletId): array
    {
        /*
         * Hanya jurnal yang MENYENTUH kas yang diperhitungkan. Subkueri `whereExists` dipakai, bukan
         * join, supaya jurnal dengan dua baris kas (transfer) tidak tergandakan.
         */
        $rows = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->whereBetween('j.journal_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereNotIn('jl.account_id', $cashIds)
            ->when($outletId !== null, fn ($q) => $q->where('jl.outlet_id', $outletId))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('journal_lines as kas')
                ->whereColumn('kas.journal_id', 'jl.journal_id')
                ->whereIn('kas.account_id', $cashIds))
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('jl.account_id, a.code, a.name, a.type, coalesce(sum(jl.debit),0) as debit, coalesce(sum(jl.credit),0) as credit')
            ->get();

        $buckets = [self::OPERATING => [], self::INVESTING => [], self::FINANCING => []];
        foreach ($rows as $row) {
            // Sumbangan baris non-kas terhadap arus kas = kredit − debit. Lihat penjelasan di atas.
            $nilai = BigDecimal::of((string) $row->credit)->minus((string) $row->debit);
            if ($nilai->isZero()) {
                continue;
            }
            $bagian = $this->classify((string) $row->type, (string) $row->code);
            $buckets[$bagian][(string) $row->account_id] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'amount' => $nilai,
            ];
        }

        return $buckets;
    }

    private function classify(string $type, string $code): string
    {
        if ($type === Account::EQUITY) {
            return self::FINANCING;
        }
        if ($type === Account::LIABILITY && str_starts_with($code, self::LONG_TERM_LIABILITY_PREFIX)) {
            return self::FINANCING;
        }
        if ($type === Account::ASSET && str_starts_with($code, self::FIXED_ASSET_PREFIX)) {
            return self::INVESTING;
        }

        return self::OPERATING;
    }

    /** @return list<string> */
    public function cashAccountIds(): array
    {
        $induk = Account::query()->where('code', self::CASH_PARENT)->value('id');
        if (! is_string($induk)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = Account::query()->where('parent_id', $induk)->where('is_postable', true)->pluck('id')->all();

        return $ids;
    }

    /** @param  list<string>  $cashIds */
    private function cashBalance(array $cashIds, CarbonImmutable $asOf, ?string $outletId): BigDecimal
    {
        $row = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->whereIn('jl.account_id', $cashIds)
            ->where('j.journal_date', '<=', $asOf->format('Y-m-d'))
            ->when($outletId !== null, fn ($q) => $q->where('jl.outlet_id', $outletId))
            ->selectRaw('coalesce(sum(jl.debit),0) as d, coalesce(sum(jl.credit),0) as c')
            ->first();

        return BigDecimal::of((string) ($row->d ?? '0'))->minus((string) ($row->c ?? '0'));
    }

    private function emptyTable(CarbonImmutable $from, CarbonImmutable $to, string $catatan): ReportTable
    {
        return new ReportTable(
            key: 'arus-kas',
            title: 'Laporan Arus Kas',
            subtitle: $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            columns: [
                'name' => ['label' => 'Keterangan', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Nilai', 'type' => ReportTable::MONEY],
            ],
            rows: [],
            notes: [$catatan],
        );
    }
}
