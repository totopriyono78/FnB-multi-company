<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\SettlementBatch;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pencairan settlement: memindahkan piutang penyedia pembayaran menjadi uang di bank (ACC-12).
 *
 * Ini pasangan yang hilang dari jurnal penjualan otomatis. Jurnal penjualan MENAMBAH piutang
 * settlement setiap ada transaksi non-tunai; tanpa kelas ini tidak ada apa pun yang menguranginya,
 * dan Neraca perlahan menampilkan piutang yang tidak pernah ada di dunia nyata.
 *
 * Jurnalnya:
 *
 * ```
 * Dr  Kas/Bank                sebesar yang benar-benar masuk rekening
 * Dr  Biaya settlement        potongan yang baru muncul saat pencairan
 *     Cr  Piutang Settlement   sebesar piutang yang dibersihkan
 * ```
 *
 * Lahir sebagai draft dan mengikuti maker–checker seperti jurnal lain: uang masuk rekening adalah
 * pernyataan yang pantas diperiksa orang kedua.
 */
class SettlementService
{
    /**
     * Metode yang dananya ditahan penyedia pembayaran lebih dulu.
     *
     * `cash` masuk laci seketika dan `transfer` langsung ke rekening — keduanya tidak pernah
     * menjadi piutang, jadi tidak ada yang perlu dicairkan.
     */
    public const METHODS = ['debit', 'credit', 'qris', 'ewallet'];

    public function __construct(
        private readonly JournalMap $map,
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Catat satu pencairan dan susun jurnal drafnya.
     *
     * @param  array{method: string, settled_on: string, gross_amount: string, fee_amount?: string,
     *               bank_account_id: string, fee_account_id?: string|null, outlet_id?: string|null,
     *               reference?: string|null, note?: string|null}  $data
     */
    public function record(array $data, User $actor): SettlementBatch
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $method = (string) ($data['method'] ?? '');
        if (! in_array($method, self::METHODS, true)) {
            throw new AccountingException('SETTLEMENT_METHOD',
                'Metode ini tidak pernah menjadi piutang settlement, jadi tidak ada yang perlu dicairkan.',
                422, field: 'method');
        }

        $gross = $this->amount($data['gross_amount'] ?? '0', 'gross_amount');
        $fee = $this->amount($data['fee_amount'] ?? '0', 'fee_amount', allowZero: true);
        $net = $gross->minus($fee);
        if (! $net->isPositiveOrZero()) {
            throw new AccountingException('SETTLEMENT_FEE_TOO_LARGE',
                'Potongan tidak boleh melebihi nilai yang dicairkan.', 422, field: 'fee_amount');
        }

        $date = CarbonImmutable::parse((string) ($data['settled_on'] ?? 'today'))->startOfDay();
        $bank = $this->postableAccount((string) ($data['bank_account_id'] ?? ''), 'bank_account_id');
        $feeChosen = isset($data['fee_account_id']) && $data['fee_account_id'] !== '' ? (string) $data['fee_account_id'] : null;
        $feeAccount = match (true) {
            $fee->isZero() => null,
            $feeChosen !== null => $this->postableAccount($feeChosen, 'fee_account_id'),
            // Tanpa pilihan khusus, potongannya jatuh ke akun biaya transaksi yang sudah dipetakan.
            default => $this->map->account(JournalMap::SETTLEMENT_FEE),
        };

        $piutang = $this->map->account(JournalMap::paymentSlot($method));

        return DB::transaction(function () use ($companyId, $data, $method, $date, $gross, $fee, $net, $bank, $feeAccount, $piutang, $actor): SettlementBatch {
            $lines = [];
            if ($net->isPositive()) {
                $lines[] = ['account_id' => $bank->id, 'debit' => (string) $net->toScale(2), 'credit' => '0',
                    'memo' => 'Pencairan '.$method, 'outlet_id' => $data['outlet_id'] ?? null];
            }
            if ($fee->isPositive() && $feeAccount !== null) {
                $lines[] = ['account_id' => $feeAccount->id, 'debit' => (string) $fee->toScale(2), 'credit' => '0',
                    'memo' => 'Potongan pencairan '.$method, 'outlet_id' => $data['outlet_id'] ?? null];
            }
            $lines[] = ['account_id' => $piutang->id, 'debit' => '0', 'credit' => (string) $gross->toScale(2),
                'memo' => 'Piutang settlement '.$method, 'outlet_id' => $data['outlet_id'] ?? null];

            $journal = $this->journals->create([
                'journal_date' => $date->format('Y-m-d'),
                'description' => 'Pencairan settlement '.$method.' '.$date->format('d M Y'),
                'source' => 'settlement',
                'lines' => $lines,
            ], $actor);

            $batch = new SettlementBatch;
            $batch->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'outlet_id' => $data['outlet_id'] ?? null,
                'method' => $method,
                'settled_on' => $date->format('Y-m-d'),
                'gross_amount' => (string) $gross->toScale(2),
                'fee_amount' => (string) $fee->toScale(2),
                'net_amount' => (string) $net->toScale(2),
                'bank_account_id' => $bank->id,
                'fee_account_id' => $feeAccount?->id,
                'reference' => $this->text($data['reference'] ?? null, 100),
                'note' => $this->text($data['note'] ?? null, 300),
                'journal_id' => $journal->id,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('settlement.recorded', $batch, new: [
                'method' => $method, 'gross' => (string) $gross->toScale(2), 'journal' => $journal->number,
            ], userId: $actor->id);

            return $batch;
        });
    }

    /**
     * Sisa piutang settlement per metode, per tanggal.
     *
     * Dihitung dari sumbernya sendiri — pembayaran POS dikurangi MDR, dikurangi retur, dikurangi
     * pencairan yang **sudah diposting**. Sengaja hanya menghitung pencairan yang sudah masuk buku
     * besar, supaya angka di laporan ini tidak pernah berbeda dari saldo akunnya di Neraca.
     */
    public function outstanding(CarbonImmutable $asOf): ReportTable
    {
        $tanggal = $asOf->format('Y-m-d');

        $penjualan = DB::table('payments as p')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'p.order_id')->on('o.business_date', '=', 'p.business_date');
            })
            ->where('o.status', '<>', 'voided')
            ->where('p.business_date', '<=', $tanggal)
            ->whereIn('p.method', self::METHODS)
            ->groupBy('p.method')
            ->selectRaw('p.method, coalesce(sum(p.amount - p.mdr_amount), 0) as nilai')
            ->pluck('nilai', 'method');

        $retur = DB::table('refunds')
            ->where('business_date', '<=', $tanggal)
            ->whereIn('method', self::METHODS)
            ->groupBy('method')
            ->selectRaw('method, coalesce(sum(amount), 0) as nilai')
            ->pluck('nilai', 'method');

        $cair = DB::table('settlement_batches as s')
            ->join('journals as j', 'j.id', '=', 's.journal_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->where('s.settled_on', '<=', $tanggal)
            ->groupBy('s.method')
            ->selectRaw('s.method, coalesce(sum(s.gross_amount), 0) as nilai')
            ->pluck('nilai', 'method');

        $tertunda = DB::table('settlement_batches as s')
            ->join('journals as j', 'j.id', '=', 's.journal_id')
            ->whereNotIn('j.status', Journal::IN_LEDGER)
            ->where('s.settled_on', '<=', $tanggal)
            ->groupBy('s.method')
            ->selectRaw('s.method, coalesce(sum(s.gross_amount), 0) as nilai')
            ->pluck('nilai', 'method');

        $rows = [];
        $totals = ['sales' => BigDecimal::zero(), 'refund' => BigDecimal::zero(),
            'settled' => BigDecimal::zero(), 'pending' => BigDecimal::zero(), 'outstanding' => BigDecimal::zero()];

        foreach (self::METHODS as $method) {
            $jual = BigDecimal::of((string) ($penjualan[$method] ?? '0'));
            $kembali = BigDecimal::of((string) ($retur[$method] ?? '0'));
            $sudah = BigDecimal::of((string) ($cair[$method] ?? '0'));
            $menunggu = BigDecimal::of((string) ($tertunda[$method] ?? '0'));
            $sisa = $jual->minus($kembali)->minus($sudah);

            if ($jual->isZero() && $sudah->isZero() && $menunggu->isZero()) {
                continue;
            }

            $row = [
                'method' => PaymentMethods::DEFAULTS[$method]['label'],
                'sales' => (string) $jual->toScale(2),
                'refund' => (string) $kembali->toScale(2),
                'settled' => (string) $sudah->toScale(2),
                'pending' => (string) $menunggu->toScale(2),
                'outstanding' => (string) $sisa->toScale(2),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] = $totals[$key]->plus($row[$key]);
            }
            $rows[] = $row;
        }

        return new ReportTable(
            key: 'piutang-settlement',
            title: 'Piutang Settlement',
            subtitle: 'Dana non-tunai yang belum masuk rekening',
            columns: [
                'method' => ['label' => 'Metode', 'type' => ReportTable::TEXT],
                'sales' => ['label' => 'Dari penjualan (neto MDR)', 'type' => ReportTable::MONEY],
                'refund' => ['label' => 'Retur', 'type' => ReportTable::MONEY],
                'settled' => ['label' => 'Sudah cair', 'type' => ReportTable::MONEY],
                'pending' => ['label' => 'Dicatat, belum diposting', 'type' => ReportTable::MONEY],
                'outstanding' => ['label' => 'Sisa piutang', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['method' => 'TOTAL'] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals),
            filters: ['Per tanggal' => $asOf->format('d M Y')],
            notes: array_values(array_filter([
                'Sisa piutang = pembayaran non-tunai (setelah MDR) − retur − pencairan yang sudah diposting.',
                'Angka ini seharusnya sama dengan saldo akun Piutang Settlement di Neraca pada tanggal yang sama.',
                $totals['pending']->isZero() ? null
                    : 'Ada pencairan yang sudah dicatat tetapi jurnalnya belum diposting; piutangnya baru berkurang setelah diposting.',
            ])),
        );
    }

    private function amount(mixed $value, string $field, bool $allowZero = false): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1) {
            throw new AccountingException('SETTLEMENT_AMOUNT', 'Nilai harus berupa angka.', 422, field: $field);
        }
        $amount = BigDecimal::of($text);
        if (! $allowZero && ! $amount->isPositive()) {
            throw new AccountingException('SETTLEMENT_AMOUNT', 'Nilai harus lebih dari nol.', 422, field: $field);
        }

        return $amount;
    }

    private function postableAccount(string $id, string $field): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->find($id);
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new AccountingException('ACCOUNT_NOT_POSTABLE',
                'Akun tujuan tidak dapat dijurnal.', 422, field: $field);
        }

        return $account;
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
