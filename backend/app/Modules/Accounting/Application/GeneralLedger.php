<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Reporting\Application\ReportTable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Buku besar dan neraca saldo (ACC-08).
 *
 * Dibentuk sebagai `ReportTable` — bentuk yang sama dipakai halaman, Excel, dan PDF (ADR 0006),
 * jadi ekspor dan jadwal email tidak perlu kode sendiri.
 *
 * **Yang dihitung hanya jurnal yang sudah diposting.** Jurnal draft sengaja tidak ikut: buku besar
 * yang memuat angka yang belum disetujui siapa pun bukan buku besar. Jurnal berstatus `reversed`
 * TETAP ikut — pembalikannya adalah jurnal tersendiri yang juga ikut, dan keduanya saling menihilkan.
 * Mengeluarkan yang asli akan menghitung pembalikannya dua kali.
 */
class GeneralLedger
{
    /**
     * Neraca saldo: saldo awal, mutasi periode, saldo akhir — per akun yang bergerak atau bersaldo.
     */
    public function trialBalance(CarbonImmutable $from, CarbonImmutable $to): ReportTable
    {
        $opening = $this->sums(null, $from->subDay());
        $movement = $this->sums($from, $to);

        $ids = array_values(array_unique([...array_keys($opening), ...array_keys($movement)]));
        $accounts = Account::query()->whereIn('id', $ids)->orderBy('code')->get();

        $rows = [];
        $totals = ['opening_debit' => BigDecimal::zero(), 'opening_credit' => BigDecimal::zero(),
            'debit' => BigDecimal::zero(), 'credit' => BigDecimal::zero(),
            'closing_debit' => BigDecimal::zero(), 'closing_credit' => BigDecimal::zero()];

        foreach ($accounts as $account) {
            $awal = $this->signed($account, $opening[$account->id] ?? null);
            $d = BigDecimal::of($movement[$account->id]['debit'] ?? '0');
            $c = BigDecimal::of($movement[$account->id]['credit'] ?? '0');
            $akhir = $awal->plus($this->signed($account, $movement[$account->id] ?? null));

            if ($awal->isZero() && $d->isZero() && $c->isZero() && $akhir->isZero()) {
                continue;
            }

            $row = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => Account::TYPE_LABEL[$account->type] ?? $account->type,
                'opening_debit' => $this->side($account, $awal, 'debit'),
                'opening_credit' => $this->side($account, $awal, 'credit'),
                'debit' => (string) $d->toScale(2),
                'credit' => (string) $c->toScale(2),
                'closing_debit' => $this->side($account, $akhir, 'debit'),
                'closing_credit' => $this->side($account, $akhir, 'credit'),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] = $totals[$key]->plus($row[$key]);
            }
            $rows[] = $row;
        }

        $seimbang = $totals['closing_debit']->isEqualTo($totals['closing_credit']);

        return new ReportTable(
            key: 'neraca-saldo',
            title: 'Neraca Saldo',
            columns: [
                'code' => ['label' => 'Kode', 'type' => ReportTable::TEXT],
                'name' => ['label' => 'Nama Akun', 'type' => ReportTable::TEXT],
                'opening_debit' => ['label' => 'Saldo Awal (D)', 'type' => ReportTable::MONEY],
                'opening_credit' => ['label' => 'Saldo Awal (K)', 'type' => ReportTable::MONEY],
                'debit' => ['label' => 'Mutasi Debit', 'type' => ReportTable::MONEY],
                'credit' => ['label' => 'Mutasi Kredit', 'type' => ReportTable::MONEY],
                'closing_debit' => ['label' => 'Saldo Akhir (D)', 'type' => ReportTable::MONEY],
                'closing_credit' => ['label' => 'Saldo Akhir (K)', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['code' => 'TOTAL', 'name' => ''] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals),
            filters: ['Periode' => $from->format('d M Y').' – '.$to->format('d M Y')],
            notes: array_values(array_filter([
                'Hanya jurnal yang sudah diposting yang dihitung; jurnal draft tidak ikut.',
                $seimbang ? null : 'PERINGATAN: total debit dan kredit tidak sama. Laporkan temuan ini — buku besar seharusnya selalu seimbang.',
                'Tutup buku tahunan belum tersedia, sehingga saldo awal akun pendapatan dan beban masih berjalan sejak transaksi pertama.',
            ])),
        );
    }

    /** Buku besar satu akun: tiap baris jurnal berikut saldo berjalannya. */
    public function ledger(Account $account, CarbonImmutable $from, CarbonImmutable $to): ReportTable
    {
        $awal = $this->signed($account, $this->sums(null, $from->subDay(), $account->id)[$account->id] ?? null);

        $lines = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->where('jl.company_id', $account->company_id)
            ->where('jl.account_id', $account->id)
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->whereBetween('j.journal_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('j.journal_date')->orderBy('j.number')->orderBy('jl.line_no')
            ->get(['j.number', 'j.journal_date', 'j.description', 'jl.memo', 'jl.debit', 'jl.credit']);

        $saldo = $awal;
        $rows = [[
            'date' => $from->format('Y-m-d'), 'number' => '', 'description' => 'Saldo awal',
            'debit' => '0.00', 'credit' => '0.00', 'balance' => (string) $awal->toScale(2),
        ]];
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        foreach ($lines as $line) {
            $d = BigDecimal::of((string) $line->debit);
            $c = BigDecimal::of((string) $line->credit);
            $debit = $debit->plus($d);
            $credit = $credit->plus($c);
            $saldo = $saldo->plus($account->normal_balance === 'debit' ? $d->minus($c) : $c->minus($d));
            $rows[] = [
                'date' => (string) $line->journal_date,
                'number' => (string) $line->number,
                'description' => (string) ($line->memo ?? $line->description),
                'debit' => (string) $d->toScale(2),
                'credit' => (string) $c->toScale(2),
                'balance' => (string) $saldo->toScale(2),
            ];
        }

        return new ReportTable(
            key: 'buku-besar',
            title: 'Buku Besar — '.$account->label(),
            subtitle: 'Saldo normal '.($account->normal_balance === 'debit' ? 'debit' : 'kredit'),
            columns: [
                'date' => ['label' => 'Tanggal', 'type' => ReportTable::TEXT],
                'number' => ['label' => 'No. Jurnal', 'type' => ReportTable::TEXT],
                'description' => ['label' => 'Keterangan', 'type' => ReportTable::TEXT],
                'debit' => ['label' => 'Debit', 'type' => ReportTable::MONEY],
                'credit' => ['label' => 'Kredit', 'type' => ReportTable::MONEY],
                'balance' => ['label' => 'Saldo', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['date' => '', 'number' => '', 'description' => 'TOTAL',
                'debit' => (string) $debit->toScale(2), 'credit' => (string) $credit->toScale(2),
                'balance' => (string) $saldo->toScale(2)],
            filters: ['Periode' => $from->format('d M Y').' – '.$to->format('d M Y')],
            notes: ['Saldo berjalan mengikuti saldo normal akun ini.'],
        );
    }

    /**
     * Jumlah debit & kredit per akun dalam rentang tanggal.
     *
     * @return array<string, array{debit: string, credit: string}>
     */
    private function sums(?CarbonImmutable $from, CarbonImmutable $to, ?string $accountId = null): array
    {
        return DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->when($from !== null, fn ($q) => $q->where('j.journal_date', '>=', $from->format('Y-m-d')))
            ->where('j.journal_date', '<=', $to->format('Y-m-d'))
            ->when($accountId !== null, fn ($q) => $q->where('jl.account_id', $accountId))
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->account_id => ['debit' => (string) $r->debit, 'credit' => (string) $r->credit]])
            ->all();
    }

    /**
     * Saldo menurut arah normal akun: positif berarti searah saldo normalnya.
     *
     * @param  array{debit: string, credit: string}|null  $sums
     */
    private function signed(Account $account, ?array $sums): BigDecimal
    {
        $d = BigDecimal::of($sums['debit'] ?? '0');
        $c = BigDecimal::of($sums['credit'] ?? '0');

        return $account->normal_balance === 'debit' ? $d->minus($c) : $c->minus($d);
    }

    /**
     * Saldo ditempatkan di kolom debit atau kredit sesuai arah nyatanya.
     * Saldo negatif (berlawanan arah normal) muncul di kolom seberang, bukan sebagai angka minus.
     */
    private function side(Account $account, BigDecimal $balance, string $column): string
    {
        $normal = $account->normal_balance;
        if ($balance->isZero()) {
            return '0.00';
        }
        $arah = $balance->isPositive() ? $normal : ($normal === 'debit' ? 'credit' : 'debit');

        return $arah === $column ? (string) $balance->abs()->toScale(2) : '0.00';
    }
}
