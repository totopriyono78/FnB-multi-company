<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\Customer;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use App\Modules\Treasury\Domain\Models\SalesInvoiceLine;
use App\Modules\Treasury\Domain\Models\SalesInvoiceReceipt;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tagihan keluar & piutang usaha (AR-01, AR-02).
 *
 * Jurnalnya cermin dari faktur pembelian:
 *
 * ```
 * Terbit    Dr  Piutang Usaha            seluruh nilai tagihan
 *               Cr  Pendapatan tiap baris
 *               Cr  PPN Keluaran             bila berfaktur pajak
 *
 * Dilunasi  Dr  Kas/Bank
 *               Cr  Piutang Usaha
 * ```
 *
 * Pelunasan **tidak menyentuh pendapatan**: pendapatannya sudah diakui saat tagihan terbit. Itu
 * bukan detail teknis — itulah yang membuat laba bulan ini tidak bergantung pada kapan pelanggan
 * membayar.
 */
class ReceivableService
{
    public const RECEIVABLE_CODE = '1210';

    public const OUTPUT_TAX_CODE = '2202';

    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_id: string, invoice_date?: string, due_date?: string|null,
     *               outlet_id?: string|null, description: string, has_tax_invoice?: bool,
     *               tax_amount?: string|null, tax_invoice_no?: string|null,
     *               tax_invoice_date?: string|null, lines: list<array<string, mixed>>}  $data
     */
    public function create(array $data, User $actor): SalesInvoice
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $customer = $this->customer((string) ($data['customer_id'] ?? ''));
        $date = CarbonImmutable::parse((string) ($data['invoice_date'] ?? 'today'))->startOfDay();
        $lines = $this->validateLines($data['lines'] ?? []);
        $pajak = $this->tax($data);

        return DB::transaction(function () use ($companyId, $customer, $date, $lines, $pajak, $data, $actor): SalesInvoice {
            $subtotal = $this->sum($lines);
            $invoice = new SalesInvoice;
            $invoice->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('FJ', $date),
                'customer_id' => $customer->id,
                'invoice_date' => $date->format('Y-m-d'),
                'due_date' => $this->dueDate($data['due_date'] ?? null, $date, $customer),
                'outlet_id' => $this->outlet($data['outlet_id'] ?? null),
                'subtotal' => (string) $subtotal->toScale(2),
                'has_tax_invoice' => $pajak['has'],
                'tax_amount' => (string) $pajak['amount']->toScale(2),
                'tax_invoice_no' => $pajak['no'],
                'tax_invoice_date' => $pajak['date'],
                'total' => (string) $subtotal->plus($pajak['amount'])->toScale(2),
                'paid_amount' => '0.00',
                'status' => SalesInvoice::DRAFT,
                'description' => $this->text($data['description'] ?? '', 300, 'description'),
                'created_by' => $actor->id,
            ])->save();

            $this->writeLines($invoice, $lines);
            $this->audit->log('sales_invoice.created', $invoice, new: [
                'number' => $invoice->number, 'customer' => $customer->name, 'total' => $invoice->total,
            ], userId: $actor->id);

            return $invoice->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(SalesInvoice $invoice, array $data, User $actor): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor): SalesInvoice {
            /** @var SalesInvoice $locked */
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $customer = array_key_exists('customer_id', $data)
                ? $this->customer((string) $data['customer_id'])
                : $this->customer($locked->customer_id);
            $date = array_key_exists('invoice_date', $data)
                ? CarbonImmutable::parse((string) $data['invoice_date'])->startOfDay()
                : $locked->invoice_date;

            $lines = array_key_exists('lines', $data) ? $this->validateLines($data['lines']) : null;
            $subtotal = $lines !== null ? $this->sum($lines) : BigDecimal::of($locked->subtotal);
            $pajak = $this->tax([
                'has_tax_invoice' => $data['has_tax_invoice'] ?? $locked->has_tax_invoice,
                'tax_amount' => $data['tax_amount'] ?? $locked->tax_amount,
                'tax_invoice_no' => $data['tax_invoice_no'] ?? $locked->tax_invoice_no,
                'tax_invoice_date' => $data['tax_invoice_date'] ?? $locked->tax_invoice_date?->format('Y-m-d'),
            ]);

            $locked->forceFill([
                'customer_id' => $customer->id,
                'invoice_date' => $date->format('Y-m-d'),
                'due_date' => array_key_exists('due_date', $data)
                    ? $this->dueDate($data['due_date'], $date, $customer) : $locked->due_date?->format('Y-m-d'),
                'outlet_id' => array_key_exists('outlet_id', $data) ? $this->outlet($data['outlet_id']) : $locked->outlet_id,
                'description' => array_key_exists('description', $data)
                    ? $this->text($data['description'], 300, 'description') : $locked->description,
                'subtotal' => (string) $subtotal->toScale(2),
                'has_tax_invoice' => $pajak['has'],
                'tax_amount' => (string) $pajak['amount']->toScale(2),
                'tax_invoice_no' => $pajak['no'],
                'tax_invoice_date' => $pajak['date'],
                'total' => (string) $subtotal->plus($pajak['amount'])->toScale(2),
            ])->save();

            if ($lines !== null) {
                $locked->lines()->delete();
                $this->writeLines($locked, $lines);
            }

            $this->audit->log('sales_invoice.updated', $locked, new: ['number' => $locked->number], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /** Terbitkan tagihan: piutangnya muncul dan pendapatannya diakui. */
    public function issue(SalesInvoice $invoice, User $actor): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): SalesInvoice {
            /** @var SalesInvoice $locked */
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->with('lines')->firstOrFail();
            $this->assertEditable($locked);

            if ($locked->lines->isEmpty()) {
                throw new TreasuryException('INVOICE_EMPTY', 'Tagihan tanpa baris tidak dapat diterbitkan.', 422, field: 'lines');
            }

            $lines = [[
                'account_id' => $this->accountByCode(self::RECEIVABLE_CODE, 'Piutang Usaha')->id,
                'debit' => (string) BigDecimal::of($locked->total)->toScale(2),
                'credit' => '0',
                'memo' => 'Tagihan kepada '.$locked->customer->name,
                'outlet_id' => $locked->outlet_id,
            ]];
            foreach ($locked->lines as $line) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'debit' => '0',
                    'credit' => (string) BigDecimal::of($line->amount)->toScale(2),
                    'memo' => mb_substr($line->description, 0, 300),
                    'outlet_id' => $locked->outlet_id,
                ];
            }
            if ($locked->has_tax_invoice && BigDecimal::of($locked->tax_amount)->isPositive()) {
                $lines[] = [
                    'account_id' => $this->accountByCode(self::OUTPUT_TAX_CODE, 'PPN Keluaran')->id,
                    'debit' => '0',
                    'credit' => (string) BigDecimal::of($locked->tax_amount)->toScale(2),
                    'memo' => 'PPN keluaran '.($locked->tax_invoice_no ?? ''),
                    'outlet_id' => $locked->outlet_id,
                ];
            }

            $journal = $this->journals->create([
                'journal_date' => $locked->invoice_date->format('Y-m-d'),
                'description' => 'Tagihan '.$locked->number.' — '.$locked->customer->name,
                'source' => 'receivable',
                'lines' => $lines,
            ], $actor);

            $locked->forceFill(['status' => SalesInvoice::ISSUED, 'journal_id' => $journal->id])->save();
            $this->audit->log('sales_invoice.issued', $locked, new: [
                'number' => $locked->number, 'total' => $locked->total, 'journal' => $journal->number,
            ], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Catat pelunasan dari pelanggan.
     *
     * @param  array{cash_account_id: string, received_on?: string, amount: string, reference?: string|null}  $data
     */
    public function receive(SalesInvoice $invoice, array $data, User $actor): SalesInvoiceReceipt
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $date = CarbonImmutable::parse((string) ($data['received_on'] ?? 'today'))->startOfDay();
        $cash = $this->cashAccount((string) ($data['cash_account_id'] ?? ''));

        return DB::transaction(function () use ($invoice, $data, $companyId, $date, $cash, $actor): SalesInvoiceReceipt {
            /** @var SalesInvoice $locked */
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [SalesInvoice::ISSUED, SalesInvoice::PAID], true)) {
                throw new TreasuryException('INVOICE_NOT_ISSUED',
                    'Hanya tagihan yang sudah diterbitkan yang bisa menerima pelunasan.', 409, field: 'status');
            }

            $amount = $this->money($data['amount'] ?? '0', 'amount', allowZero: false);
            $sisa = $locked->outstanding();
            if ($amount->isGreaterThan($sisa)) {
                throw new TreasuryException('RECEIPT_EXCEEDS_INVOICE',
                    'Nilai pelunasan melebihi sisa tagihan (sisa '.$sisa->toScale(2).').',
                    422, field: 'amount', details: ['outstanding' => (string) $sisa->toScale(2)]);
            }

            $journal = $this->journals->create([
                'journal_date' => $date->format('Y-m-d'),
                'description' => 'Pelunasan '.$locked->number.' — '.$locked->customer->name,
                'source' => 'receivable',
                'lines' => [
                    ['account_id' => $cash->account_id, 'debit' => (string) $amount->toScale(2), 'credit' => '0',
                        'memo' => 'Pelunasan '.$locked->number, 'outlet_id' => $locked->outlet_id],
                    ['account_id' => $this->accountByCode(self::RECEIVABLE_CODE, 'Piutang Usaha')->id,
                        'debit' => '0', 'credit' => (string) $amount->toScale(2),
                        'memo' => 'Pelunasan dari '.$locked->customer->name, 'outlet_id' => $locked->outlet_id],
                ],
            ], $actor);

            $receipt = new SalesInvoiceReceipt;
            $receipt->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('PP', $date),
                'sales_invoice_id' => $locked->id,
                'cash_account_id' => $cash->id,
                'received_on' => $date->format('Y-m-d'),
                'amount' => (string) $amount->toScale(2),
                'reference' => $this->optional($data['reference'] ?? null, 100),
                'journal_id' => $journal->id,
                'created_by' => $actor->id,
            ])->save();

            $terbayar = BigDecimal::of($locked->paid_amount)->plus($amount);
            $locked->forceFill([
                'paid_amount' => (string) $terbayar->toScale(2),
                'status' => $terbayar->isGreaterThanOrEqualTo($locked->total) ? SalesInvoice::PAID : $locked->status,
            ])->save();

            $this->audit->log('sales_invoice.received', $receipt, new: [
                'number' => $receipt->number, 'invoice' => $locked->number,
                'amount' => $receipt->amount, 'journal' => $journal->number,
            ], userId: $actor->id);

            return $receipt;
        });
    }

    public function cancel(SalesInvoice $invoice, User $actor, string $reason): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): SalesInvoice {
            /** @var SalesInvoice $locked */
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesInvoice::DRAFT) {
                throw new TreasuryException('INVOICE_NOT_DRAFT',
                    'Tagihan yang sudah diterbitkan tidak dibatalkan, melainkan dikoreksi lewat jurnal balik.',
                    409, field: 'status');
            }
            $locked->forceFill(['status' => SalesInvoice::CANCELLED])->save();
            $this->audit->log('sales_invoice.cancelled', $locked,
                new: ['number' => $locked->number], reason: $reason, userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function delete(SalesInvoice $invoice, User $actor): void
    {
        $this->assertEditable($invoice);
        $this->audit->log('sales_invoice.deleted', $invoice, old: ['number' => $invoice->number], userId: $actor->id);
        $invoice->lines()->delete();
        $invoice->delete();
    }

    /** Umur piutang (AR-02): cermin dari umur hutang, dengan ember umur yang sama persis. */
    public function aging(?CarbonImmutable $asOf = null): ReportTable
    {
        $per = $asOf ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

        /** @var iterable<SalesInvoice> $invoices */
        $invoices = SalesInvoice::query()
            ->whereIn('status', [SalesInvoice::ISSUED, SalesInvoice::PAID])
            ->where('invoice_date', '<=', $per->format('Y-m-d'))
            ->with('customer:id,name')
            ->get();

        $kolom = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];
        $perPelanggan = [];
        $totals = array_fill_keys($kolom, BigDecimal::zero()) + ['total' => BigDecimal::zero()];

        foreach ($invoices as $invoice) {
            $sisa = $invoice->outstanding();
            if ($sisa->isNegativeOrZero()) {
                continue;
            }
            $nama = $invoice->customer->name;
            $perPelanggan[$nama] ??= array_fill_keys($kolom, BigDecimal::zero()) + ['total' => BigDecimal::zero()];

            $umur = $invoice->daysOverdue($per);
            $ember = match (true) {
                $umur <= 0 => 'not_due',
                $umur <= 30 => 'd1_30',
                $umur <= 60 => 'd31_60',
                $umur <= 90 => 'd61_90',
                default => 'd90_plus',
            };

            $perPelanggan[$nama][$ember] = $perPelanggan[$nama][$ember]->plus($sisa);
            $perPelanggan[$nama]['total'] = $perPelanggan[$nama]['total']->plus($sisa);
            $totals[$ember] = $totals[$ember]->plus($sisa);
            $totals['total'] = $totals['total']->plus($sisa);
        }

        ksort($perPelanggan);
        $rows = [];
        foreach ($perPelanggan as $nama => $nilai) {
            $rows[] = ['customer' => $nama] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $nilai);
        }

        return new ReportTable(
            key: 'umur-piutang',
            title: 'Umur Piutang Usaha',
            subtitle: 'Sisa piutang per '.$per->translatedFormat('d M Y'),
            columns: [
                'customer' => ['label' => 'Pelanggan', 'type' => ReportTable::TEXT],
                'not_due' => ['label' => 'Belum jatuh tempo', 'type' => ReportTable::MONEY],
                'd1_30' => ['label' => '1–30 hari', 'type' => ReportTable::MONEY],
                'd31_60' => ['label' => '31–60 hari', 'type' => ReportTable::MONEY],
                'd61_90' => ['label' => '61–90 hari', 'type' => ReportTable::MONEY],
                'd90_plus' => ['label' => '> 90 hari', 'type' => ReportTable::MONEY],
                'total' => ['label' => 'Jumlah', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['customer' => 'JUMLAH'] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals),
            notes: [
                'Hanya tagihan keluar yang dihitung. Piutang settlement kartu & QRIS punya laporannya sendiri di Akuntansi → Pencairan Settlement.',
                'Jumlah di laporan ini seharusnya sama dengan saldo akun Piutang Usaha di Neraca pada tanggal yang sama.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{has: bool, amount: BigDecimal, no: string|null, date: string|null}
     */
    private function tax(array $data): array
    {
        $has = (bool) ($data['has_tax_invoice'] ?? false);
        if (! $has) {
            return ['has' => false, 'amount' => BigDecimal::zero(), 'no' => null, 'date' => null];
        }

        $no = $this->optional($data['tax_invoice_no'] ?? null, 60);
        if ($no === null) {
            throw new TreasuryException('TAX_INVOICE_NO_REQUIRED',
                'Nomor faktur pajak wajib diisi bila PPN keluaran dipungut.', 422, field: 'tax_invoice_no');
        }
        $date = $data['tax_invoice_date'] ?? null;

        return [
            'has' => true,
            'amount' => $this->money($data['tax_amount'] ?? '0', 'tax_amount', allowZero: true),
            'no' => $no,
            'date' => $date === null || $date === '' ? null : CarbonImmutable::parse((string) $date)->format('Y-m-d'),
        ];
    }

    /**
     * @return list<array{description: string, account_id: string, quantity: BigDecimal,
     *                    unit_price: BigDecimal, amount: BigDecimal}>
     */
    private function validateLines(mixed $value): array
    {
        $rows = is_array($value) ? array_values($value) : [];
        $out = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'description' => $this->text($row['description'] ?? '', 200, "lines.{$i}.description"),
                'account_id' => $this->postableAccount((string) ($row['account_id'] ?? ''), "lines.{$i}.account_id")->id,
                'quantity' => $this->money($row['quantity'] ?? '1', "lines.{$i}.quantity", allowZero: false, scale: 4),
                'unit_price' => $this->money($row['unit_price'] ?? '0', "lines.{$i}.unit_price", allowZero: true, scale: 4),
                'amount' => $this->money($row['amount'] ?? '0', "lines.{$i}.amount", allowZero: false),
            ];
        }

        if ($out === []) {
            throw new TreasuryException('INVOICE_EMPTY', 'Tagihan harus punya minimal satu baris.', 422, field: 'lines');
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function sum(array $lines): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $nilai = $line['amount'];
            $total = $total->plus($nilai instanceof BigDecimal ? $nilai : BigDecimal::of((string) $nilai));
        }

        return $total;
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(SalesInvoice $invoice, array $lines): void
    {
        $no = 1;
        foreach ($lines as $line) {
            $row = new SalesInvoiceLine;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $invoice->company_id,
                'sales_invoice_id' => $invoice->id,
                'line_no' => $no++,
                'description' => $line['description'],
                'account_id' => $line['account_id'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'amount' => (string) $line['amount']->toScale(2),
            ])->save();
        }
    }

    private function assertEditable(SalesInvoice $invoice): void
    {
        if ($invoice->isEditable()) {
            return;
        }

        throw new TreasuryException('INVOICE_NOT_EDITABLE',
            'Tagihan yang sudah diterbitkan tidak dapat diubah. Koreksinya lewat jurnal balik.',
            409, field: 'status');
    }

    private function customer(string $id): Customer
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->find($id);
        if ($customer === null) {
            throw new TreasuryException('CUSTOMER_NOT_FOUND', 'Pelanggan tidak ditemukan.', 422, field: 'customer_id');
        }

        return $customer;
    }

    private function cashAccount(string $id): CashAccount
    {
        /** @var CashAccount|null $cash */
        $cash = CashAccount::query()->find($id);
        if ($cash === null || ! $cash->is_active) {
            throw new TreasuryException('CASH_ACCOUNT_NOT_FOUND',
                'Rekening penerima tidak ditemukan atau sudah nonaktif.', 422, field: 'cash_account_id');
        }

        return $cash;
    }

    private function dueDate(mixed $value, CarbonImmutable $invoiceDate, Customer $customer): string
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        }

        return $invoiceDate->addDays(max(0, $customer->payment_term_days))->format('Y-m-d');
    }

    private function accountByCode(string $code, string $name): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->where('code', $code)->first();
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new TreasuryException('ACCOUNT_MISSING',
                "Akun {$code} ({$name}) tidak ada atau tidak dapat dijurnal. Pasang template bagan akun lebih dulu.",
                422, field: 'account');
        }

        return $account;
    }

    private function postableAccount(string $id, string $field): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->find($id);
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new TreasuryException('ACCOUNT_NOT_POSTABLE',
                'Akun baris tidak dapat dijurnal. Pilih akun daun yang masih aktif.', 422, field: $field);
        }

        return $account;
    }

    private function money(mixed $value, string $field, bool $allowZero, int $scale = 2): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d{1,'.$scale.'})?$/', $text) !== 1) {
            throw new TreasuryException('AMOUNT_INVALID', 'Nilai harus angka tidak negatif.', 422, field: $field);
        }
        $nilai = BigDecimal::of($text);
        if (! $allowZero && $nilai->isZero()) {
            throw new TreasuryException('AMOUNT_INVALID', 'Nilai harus lebih dari nol.', 422, field: $field);
        }

        return $nilai;
    }

    private function outlet(mixed $outletId): ?string
    {
        if ($outletId === null || $outletId === '') {
            return null;
        }
        if (! Outlet::query()->whereKey((string) $outletId)->exists()) {
            throw new TreasuryException('OUTLET_NOT_FOUND', 'Outlet tidak ditemukan.', 422, field: 'outlet_id');
        }

        return (string) $outletId;
    }

    private function text(mixed $value, int $max, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < 3) {
            throw new TreasuryException('TEXT_REQUIRED', 'Isian ini wajib diisi minimal 3 huruf.', 422, field: $field);
        }

        return mb_substr($text, 0, $max);
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
