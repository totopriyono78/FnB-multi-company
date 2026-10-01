<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Laporan keuangan: Laba Rugi (FIN-01) dan Neraca (FIN-02).
 *
 * Keduanya dibaca dari buku besar yang sama dengan neraca saldo — **hanya jurnal yang sudah
 * diposting**. Tidak ada tabel ringkasan tersendiri yang bisa basi: satu-satunya sumber angka di
 * sini adalah `journal_lines`, sehingga laporan tidak mungkin berbeda dari buku besarnya.
 *
 * ## Tandanya mengikuti KELOMPOK, bukan saldo normal akunnya
 *
 * Akun lawan (contra) punya saldo normal berlawanan dengan jenisnya: Diskon Penjualan bersaldo
 * debit meski jenisnya pendapatan, Akumulasi Penyusutan bersaldo kredit meski jenisnya aset.
 * Di buku besar itu ditampilkan apa adanya. Di laporan keuangan **yang dibutuhkan pembaca adalah
 * kontribusinya terhadap kelompoknya**: diskon harus MENGURANGI pendapatan, akumulasi penyusutan
 * harus MENGURANGI aset. Karena itu tandanya ditentukan kelompok akun:
 *
 * - Aset, HPP, Beban → debit − kredit
 * - Liabilitas, Ekuitas, Pendapatan → kredit − debit
 *
 * Dengan aturan itu subtotal tiap kelompok cukup dijumlah biasa, dan akun lawan otomatis tampil
 * negatif di tempat yang benar.
 *
 * ## Kenapa Neraca selalu seimbang
 *
 * Karena tiap jurnal seimbang, untuk seluruh buku berlaku:
 *
 *     Aset + HPP + Beban  =  Liabilitas + Ekuitas + Pendapatan
 *     Aset = Liabilitas + Ekuitas + (Pendapatan − HPP − Beban)
 *
 * Suku terakhir itulah **Laba (Rugi) Berjalan** — baris ekuitas yang dihitung, bukan disimpan.
 * Selama tutup buku tahunan belum ada, ia menumpuk sejak jurnal pertama, dan itu dikatakan apa
 * adanya di catatan kaki alih-alih disebut "laba tahun berjalan" yang menyesatkan.
 */
class FinancialStatements
{
    /**
     * Laba Rugi untuk satu rentang tanggal, boleh dipersempit ke satu outlet atau brand (ACC-03).
     *
     * Dimensinya dibaca dari baris jurnal, bukan dari bagan akun yang dipecah per outlet. Memecah
     * bagan akun berarti setiap outlet baru menambah puluhan akun dan neraca saldo jadi tak
     * terbaca; dimensi menjawab pertanyaan yang sama tanpa merusak bagan akunnya.
     */
    public function incomeStatement(CarbonImmutable $from, CarbonImmutable $to, ?string $outletId = null, ?string $brandId = null): ReportTable
    {
        $saldo = $this->balances($from, $to, $outletId, $brandId);

        $rows = [];
        $pendapatan = $this->section($rows, $saldo, [Account::REVENUE], 'PENDAPATAN', 'Jumlah Pendapatan');
        $hpp = $this->section($rows, $saldo, [Account::COGS], 'HARGA POKOK PENJUALAN', 'Jumlah Harga Pokok Penjualan');

        $kotor = $pendapatan->minus($hpp);
        $rows[] = $this->line('', 'LABA KOTOR', $kotor, 'result');

        $beban = $this->section($rows, $saldo, [Account::EXPENSE], 'BEBAN OPERASIONAL', 'Jumlah Beban Operasional');

        $bersih = $kotor->minus($beban);
        $rows[] = $this->line('', 'LABA (RUGI) BERSIH', $bersih, 'result');

        $margin = $pendapatan->isZero()
            ? BigDecimal::zero()
            : $bersih->multipliedBy(100)->dividedBy($pendapatan, 2, RoundingMode::HALF_UP);

        return new ReportTable(
            key: 'laba-rugi',
            title: 'Laporan Laba Rugi',
            columns: [
                'name' => ['label' => 'Akun', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Jumlah', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: null,
            filters: array_filter([
                'Periode' => $from->format('d M Y').' – '.$to->format('d M Y'),
                'Outlet' => $outletId === null ? null : (string) (Outlet::query()->whereKey($outletId)->value('name') ?? '—'),
                'Brand' => $brandId === null ? null : (string) (Brand::query()->whereKey($brandId)->value('name') ?? '—'),
            ]),
            summary: [
                ['label' => 'Pendapatan', 'value' => (string) $pendapatan->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Laba Kotor', 'value' => (string) $kotor->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Laba (Rugi) Bersih', 'value' => (string) $bersih->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Marjin Bersih', 'value' => (string) $margin, 'type' => ReportTable::PERCENT],
            ],
            notes: array_values(array_filter([
                'Hanya jurnal yang sudah diposting yang dihitung; jurnal draft tidak ikut.',
                'Akun lawan seperti Diskon dan Retur Penjualan tampil negatif karena ia mengurangi pendapatan.',
                $outletId === null && $brandId === null ? null
                    : 'Disaring per dimensi: baris jurnal tanpa outlet/brand (mis. beban kantor pusat) tidak ikut di sini.',
                $pendapatan->isZero() && $bersih->isZero()
                    ? 'Belum ada pendapatan maupun beban terposting pada rentang ini.' : null,
            ])),
        );
    }

    /**
     * Neraca per satu tanggal, dengan kolom pembanding per tanggal lain.
     *
     * @param  CarbonImmutable  $pembanding  tanggal kolom kedua (biasanya awal periode)
     */
    public function balanceSheet(CarbonImmutable $pembanding, CarbonImmutable $per): ReportTable
    {
        $kini = $this->balances(null, $per);
        $dulu = $this->balances(null, $pembanding);

        $rows = [];
        $aset = $this->section($rows, $kini, [Account::ASSET], 'ASET', 'JUMLAH ASET', $dulu);

        $liabilitas = $this->section($rows, $kini, [Account::LIABILITY], 'LIABILITAS', 'Jumlah Liabilitas', $dulu);

        // Ekuitas ditambah satu baris yang DIHITUNG, bukan disimpan: laba yang belum ditutup.
        $ekuitasAkun = $this->section($rows, $kini, [Account::EQUITY], 'EKUITAS', null, $dulu);
        $laba = $this->profit($kini);
        $labaDulu = $this->profit($dulu);
        $rows[] = $this->line('', 'Laba (Rugi) Berjalan', $laba, 'item', $labaDulu);

        $ekuitas = $ekuitasAkun->plus($laba);
        $ekuitasDulu = $this->total($dulu, [Account::EQUITY])->plus($labaDulu);
        $rows[] = $this->line('', 'Jumlah Ekuitas', $ekuitas, 'subtotal', $ekuitasDulu);

        $kewajibanEkuitas = $liabilitas->plus($ekuitas);
        $rows[] = $this->line('', 'JUMLAH LIABILITAS & EKUITAS', $kewajibanEkuitas, 'result',
            $this->total($dulu, [Account::LIABILITY])->plus($ekuitasDulu));

        $selisih = $aset->minus($kewajibanEkuitas);

        return new ReportTable(
            key: 'neraca',
            title: 'Neraca',
            columns: [
                'name' => ['label' => 'Akun', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Per '.$per->format('d M Y'), 'type' => ReportTable::MONEY],
                'previous' => ['label' => 'Per '.$pembanding->format('d M Y'), 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: null,
            filters: ['Per tanggal' => $per->format('d M Y'), 'Pembanding' => $pembanding->format('d M Y')],
            summary: [
                ['label' => 'Jumlah Aset', 'value' => (string) $aset->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Liabilitas', 'value' => (string) $liabilitas->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Ekuitas', 'value' => (string) $ekuitas->toScale(2), 'type' => ReportTable::MONEY],
            ],
            notes: array_values(array_filter([
                'Hanya jurnal yang sudah diposting yang dihitung; jurnal draft tidak ikut.',
                '"Laba (Rugi) Berjalan" dihitung dari pendapatan dikurangi HPP dan beban. Karena tutup buku '
                    .'tahunan belum tersedia, ia menumpuk sejak jurnal pertama — bukan hanya tahun berjalan.',
                'Akun lawan seperti Akumulasi Penyusutan tampil negatif karena ia mengurangi aset.',
                $selisih->isZero() ? null
                    : 'PERINGATAN: aset tidak sama dengan liabilitas ditambah ekuitas (selisih '
                        .ReportTable::rupiah((string) $selisih->toScale(2)).'). Laporkan temuan ini — '
                        .'neraca yang dibentuk dari jurnal seimbang seharusnya tidak mungkin timpang.',
            ])),
        );
    }

    /**
     * Tulis satu kelompok akun ke `$rows` dan kembalikan jumlahnya.
     *
     * @param  list<array<string, string|int|null>>  $rows
     * @param  array<string, array{code: string, name: string, type: string, amount: BigDecimal}>  $saldo
     * @param  list<string>  $types
     * @param  array<string, array{code: string, name: string, type: string, amount: BigDecimal}>|null  $pembanding
     */
    private function section(array &$rows, array $saldo, array $types, string $heading, ?string $subtotal, ?array $pembanding = null): BigDecimal
    {
        $rows[] = $this->line('', $heading, null, 'section');

        $jumlah = BigDecimal::zero();
        $baris = array_filter($saldo, fn (array $a) => in_array($a['type'], $types, true));
        uasort($baris, fn (array $a, array $b) => strcmp($a['code'], $b['code']));

        foreach ($baris as $id => $akun) {
            $lalu = $pembanding[$id]['amount'] ?? BigDecimal::zero();
            // Akun yang nol di kedua kolom tidak ditulis: barisnya tidak menambah satu pun keterangan.
            if ($akun['amount']->isZero() && $lalu->isZero()) {
                continue;
            }
            $jumlah = $jumlah->plus($akun['amount']);
            $rows[] = $this->line($akun['code'], $akun['name'], $akun['amount'], 'item', $pembanding === null ? null : $lalu);
        }

        // Akun yang PUNYA saldo pembanding tetapi sudah nol sekarang tetap perlu tampil.
        if ($pembanding !== null) {
            foreach ($pembanding as $id => $akun) {
                if (isset($saldo[$id]) || ! in_array($akun['type'], $types, true) || $akun['amount']->isZero()) {
                    continue;
                }
                $rows[] = $this->line($akun['code'], $akun['name'], BigDecimal::zero(), 'item', $akun['amount']);
            }
        }

        if ($subtotal !== null) {
            $rows[] = $this->line('', $subtotal, $jumlah, 'subtotal',
                $pembanding === null ? null : $this->total($pembanding, $types));
        }

        return $jumlah;
    }

    /**
     * Satu baris laporan.
     *
     * Kode dan nama digabung dalam satu kolom: baris seksi dan subtotal tidak punya kode, dan kolom
     * kode yang berisi "-" di separuh baris hanya menambah derau pada laporan yang justru harus
     * paling mudah dibaca.
     *
     * @return array<string, string|int|null>
     */
    private function line(string $code, string $name, ?BigDecimal $amount, string $style, ?BigDecimal $previous = null): array
    {
        return [
            'name' => $code === '' ? $name : $code.' — '.$name,
            'amount' => $amount === null ? null : (string) $amount->toScale(2),
            'previous' => $previous === null ? null : (string) $previous->toScale(2),
            // Dibaca penyaji untuk memberi gaya baris; pengekspor mengabaikannya karena ia hanya
            // membaca kunci yang terdaftar di `columns`.
            '_style' => $style,
        ];
    }

    /**
     * @param  array<string, array{code: string, name: string, type: string, amount: BigDecimal}>  $saldo
     * @param  list<string>  $types
     */
    private function total(array $saldo, array $types): BigDecimal
    {
        $jumlah = BigDecimal::zero();
        foreach ($saldo as $akun) {
            if (in_array($akun['type'], $types, true)) {
                $jumlah = $jumlah->plus($akun['amount']);
            }
        }

        return $jumlah;
    }

    /**
     * Laba = pendapatan − HPP − beban. Inilah yang membuat neraca seimbang tanpa tutup buku.
     *
     * @param  array<string, array{code: string, name: string, type: string, amount: BigDecimal}>  $saldo
     */
    private function profit(array $saldo): BigDecimal
    {
        return $this->total($saldo, [Account::REVENUE])
            ->minus($this->total($saldo, [Account::COGS]))
            ->minus($this->total($saldo, [Account::EXPENSE]));
    }

    /**
     * Saldo tiap akun, bertanda menurut kelompoknya.
     *
     * @return array<string, array{code: string, name: string, type: string, amount: BigDecimal}>
     */
    private function balances(?CarbonImmutable $from, CarbonImmutable $to, ?string $outletId = null, ?string $brandId = null): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->when($from !== null, fn ($q) => $q->where('j.journal_date', '>=', $from->format('Y-m-d')))
            ->where('j.journal_date', '<=', $to->format('Y-m-d'))
            ->when($outletId !== null, fn ($q) => $q->where('jl.outlet_id', $outletId))
            ->when($brandId !== null, fn ($q) => $q->where('jl.brand_id', $brandId))
            ->groupBy('jl.account_id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('jl.account_id, a.code, a.name, a.type, coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $debit = BigDecimal::of((string) $row->debit);
            $credit = BigDecimal::of((string) $row->credit);
            $out[(string) $row->account_id] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'amount' => in_array((string) $row->type, Account::DEBIT_GROUPS, true)
                    ? $debit->minus($credit)
                    : $credit->minus($debit),
            ];
        }

        return $out;
    }
}
