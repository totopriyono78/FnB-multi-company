<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\GoodsReceipt;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use App\Modules\Treasury\Domain\Models\PurchaseInvoiceLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Faktur pembelian — tagihan masuk dari supplier (AP-02, TAX-02).
 *
 * Inilah yang membuat beban diakui **saat terjadi**, bukan saat dibayar:
 *
 * ```
 * Dr  Akun tiap baris        persediaan / beban / uang muka   (DPP)
 * Dr  PPN Masukan            hanya bila ada faktur pajak
 *     Cr  Utang Usaha            seluruh nilai tagihan
 * ```
 *
 * Pembayarannya kemudian **tidak menyentuh beban sama sekali** — ia hanya memindahkan Utang Usaha
 * menjadi uang keluar. Itulah sebabnya SPPK untuk faktur menunjuk Utang Usaha, bukan akun beban:
 * jurnal advis bayar yang sama persis berubah arti dari "mengakui beban" menjadi "melunasi hutang"
 * tanpa satu baris kode pun berubah.
 *
 * **PPN masukan diperlakukan campuran** (keputusan user 2 Okt 2026): ada tidaknya faktur pajak
 * ditentukan per dokumen, dengan nilai bawaan mengikuti status PKP supplier. Yang belum PKP tidak
 * pernah menyentuh akun PPN Masukan — PPN-nya menjadi bagian harga perolehan, apa adanya.
 */
class PurchaseInvoiceService
{
    /** Akun hutang usaha. Faktur yang tidak menemukannya ditolak, bukan dipaksa ke akun lain. */
    public const PAYABLE_CODE = '2101';

    public const INPUT_TAX_CODE = '1220';

    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id: string, invoice_date?: string, due_date?: string|null,
     *               supplier_invoice_no?: string|null, goods_receipt_id?: string|null,
     *               outlet_id?: string|null, description: string, has_tax_invoice?: bool,
     *               tax_amount?: string|null, tax_invoice_no?: string|null,
     *               tax_invoice_date?: string|null, lines: list<array<string, mixed>>}  $data
     */
    public function create(array $data, User $actor): PurchaseInvoice
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $supplier = $this->supplier((string) ($data['supplier_id'] ?? ''));
        $date = CarbonImmutable::parse((string) ($data['invoice_date'] ?? 'today'))->startOfDay();
        $lines = $this->validateLines($data['lines'] ?? []);
        $pajak = $this->tax($data, $supplier);

        return DB::transaction(function () use ($companyId, $supplier, $date, $lines, $pajak, $data, $actor): PurchaseInvoice {
            $subtotal = $this->sum($lines);
            $invoice = new PurchaseInvoice;
            $invoice->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('FB', $date),
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $this->optional($data['supplier_invoice_no'] ?? null, 60),
                'invoice_date' => $date->format('Y-m-d'),
                'due_date' => $this->dueDate($data['due_date'] ?? null, $date, $supplier),
                'goods_receipt_id' => $this->goodsReceipt($data['goods_receipt_id'] ?? null, $supplier),
                'outlet_id' => $this->outlet($data['outlet_id'] ?? null),
                'subtotal' => (string) $subtotal->toScale(2),
                'has_tax_invoice' => $pajak['has'],
                'tax_amount' => (string) $pajak['amount']->toScale(2),
                'tax_invoice_no' => $pajak['no'],
                'tax_invoice_date' => $pajak['date'],
                'total' => (string) $subtotal->plus($pajak['amount'])->toScale(2),
                'paid_amount' => '0.00',
                'status' => PurchaseInvoice::DRAFT,
                'description' => $this->text($data['description'] ?? '', 300, 'description'),
                'created_by' => $actor->id,
            ])->save();

            $this->writeLines($invoice, $lines);
            $this->audit->log('purchase_invoice.created', $invoice, new: [
                'number' => $invoice->number, 'supplier' => $supplier->name, 'total' => $invoice->total,
            ], userId: $actor->id);

            return $invoice->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PurchaseInvoice $invoice, array $data, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $actor): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $supplier = array_key_exists('supplier_id', $data)
                ? $this->supplier((string) $data['supplier_id'])
                : $this->supplier($locked->supplier_id);
            $date = array_key_exists('invoice_date', $data)
                ? CarbonImmutable::parse((string) $data['invoice_date'])->startOfDay()
                : $locked->invoice_date;

            $lines = array_key_exists('lines', $data) ? $this->validateLines($data['lines']) : null;
            $subtotal = $lines !== null ? $this->sum($lines) : BigDecimal::of($locked->subtotal);
            $pajak = $this->tax($data + [
                'has_tax_invoice' => $data['has_tax_invoice'] ?? $locked->has_tax_invoice,
                'tax_amount' => $data['tax_amount'] ?? $locked->tax_amount,
                'tax_invoice_no' => $data['tax_invoice_no'] ?? $locked->tax_invoice_no,
                'tax_invoice_date' => $data['tax_invoice_date'] ?? $locked->tax_invoice_date?->format('Y-m-d'),
            ], $supplier);

            $locked->forceFill([
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => array_key_exists('supplier_invoice_no', $data)
                    ? $this->optional($data['supplier_invoice_no'], 60) : $locked->supplier_invoice_no,
                'invoice_date' => $date->format('Y-m-d'),
                'due_date' => array_key_exists('due_date', $data)
                    ? $this->dueDate($data['due_date'], $date, $supplier) : $locked->due_date?->format('Y-m-d'),
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

            $this->audit->log('purchase_invoice.updated', $locked, new: ['number' => $locked->number], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Terbitkan faktur: hutangnya muncul dan bebannya diakui.
     *
     * Jurnalnya lahir draft dan tetap melewati maker–checker seperti jurnal lain — mengakui beban
     * adalah pernyataan tentang angka, dan pernyataan tentang angka diperiksa orang kedua.
     */
    public function issue(PurchaseInvoice $invoice, User $actor): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $actor): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->with('lines')->firstOrFail();
            $this->assertEditable($locked);

            if ($locked->lines->isEmpty()) {
                throw new TreasuryException('INVOICE_EMPTY',
                    'Faktur tanpa baris tidak dapat diterbitkan.', 422, field: 'lines');
            }

            $payable = $this->accountByCode(self::PAYABLE_CODE, 'Utang Usaha');
            $lines = [];
            foreach ($locked->lines as $line) {
                $lines[] = [
                    'account_id' => $line->account_id,
                    'debit' => (string) BigDecimal::of($line->amount)->toScale(2),
                    'credit' => '0',
                    'memo' => mb_substr($line->description, 0, 300),
                    'outlet_id' => $locked->outlet_id,
                ];
            }
            if ($locked->has_tax_invoice && BigDecimal::of($locked->tax_amount)->isPositive()) {
                $lines[] = [
                    'account_id' => $this->accountByCode(self::INPUT_TAX_CODE, 'PPN Masukan')->id,
                    'debit' => (string) BigDecimal::of($locked->tax_amount)->toScale(2),
                    'credit' => '0',
                    'memo' => 'PPN masukan '.($locked->tax_invoice_no ?? ''),
                    'outlet_id' => $locked->outlet_id,
                ];
            }
            $lines[] = [
                'account_id' => $payable->id,
                'debit' => '0',
                'credit' => (string) BigDecimal::of($locked->total)->toScale(2),
                'memo' => 'Hutang kepada '.$locked->supplier->name,
                'outlet_id' => $locked->outlet_id,
            ];

            $journal = $this->journals->create([
                'journal_date' => $locked->invoice_date->format('Y-m-d'),
                'description' => 'Faktur pembelian '.$locked->number.' — '.$locked->supplier->name,
                'source' => 'purchase',
                'lines' => $lines,
            ], $actor);

            $locked->forceFill(['status' => PurchaseInvoice::ISSUED, 'journal_id' => $journal->id])->save();
            $this->audit->log('purchase_invoice.issued', $locked, new: [
                'number' => $locked->number, 'total' => $locked->total, 'journal' => $journal->number,
            ], userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function cancel(PurchaseInvoice $invoice, User $actor, string $reason): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): PurchaseInvoice {
            /** @var PurchaseInvoice $locked */
            $locked = PurchaseInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseInvoice::DRAFT) {
                throw new TreasuryException('INVOICE_NOT_DRAFT',
                    'Faktur yang sudah diterbitkan tidak dibatalkan, melainkan dikoreksi lewat jurnal balik — '
                    .'membatalkannya akan meninggalkan jurnal yang tidak punya dokumen.', 409, field: 'status');
            }
            $locked->forceFill(['status' => PurchaseInvoice::CANCELLED])->save();
            $this->audit->log('purchase_invoice.cancelled', $locked,
                new: ['number' => $locked->number], reason: $reason, userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function delete(PurchaseInvoice $invoice, User $actor): void
    {
        $this->assertEditable($invoice);
        $this->audit->log('purchase_invoice.deleted', $invoice, old: ['number' => $invoice->number], userId: $actor->id);
        $invoice->lines()->delete();
        $invoice->delete();
    }

    /**
     * Baris faktur yang disiapkan dari sebuah penerimaan barang — satu baris per bahan yang diterima.
     *
     * Nilainya diambil dari penerimaan, bukan dari PO: yang menjadi hutang adalah barang yang
     * benar-benar datang. Akun bawaannya Persediaan Bahan Baku; orang entry tinggal menggantinya
     * untuk barang yang langsung dibebankan.
     *
     * @return list<array{description: string, account_id: string|null, ingredient_id: string|null,
     *                    quantity: string, unit_price: string, amount: string}>
     */
    public function linesFromGoodsReceipt(GoodsReceipt $receipt): array
    {
        $persediaan = Account::query()->where('code', '1301')->value('id');
        $out = [];

        foreach ($receipt->lines()->with('ingredient:id,name')->get() as $line) {
            /*
             * Satuan yang dipakai adalah satuan BELI (qty × unit_price), bukan satuan dasar: begitulah
             * supplier menagih, dan faktur yang satuannya berbeda dari tagihan aslinya mustahil
             * dicocokkan orang.
             */
            $qty = BigDecimal::of((string) $line->qty);
            $total = BigDecimal::of((string) $line->line_total);
            $out[] = [
                'description' => mb_substr(trim(($line->ingredient->name ?? 'Barang').' ('.$line->unit_name.')'), 0, 200),
                'account_id' => is_string($persediaan) ? $persediaan : null,
                'ingredient_id' => $line->ingredient_id,
                'quantity' => (string) $qty,
                'unit_price' => (string) BigDecimal::of((string) $line->unit_price),
                'amount' => (string) $total->toScale(2),
            ];
        }

        return $out;
    }

    /**
     * Penerimaan barang milik supplier ini yang belum pernah difakturkan.
     *
     * @return Collection<int, GoodsReceipt>
     */
    public function openGoodsReceipts(?string $supplierId = null): Collection
    {
        return GoodsReceipt::query()
            ->when($supplierId !== null && $supplierId !== '', fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereNotIn('id', PurchaseInvoice::query()
                ->whereNotNull('goods_receipt_id')
                ->where('status', '!=', PurchaseInvoice::CANCELLED)
                ->select('goods_receipt_id'))
            ->with('supplier:id,name')
            ->orderByDesc('business_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{has: bool, amount: BigDecimal, no: string|null, date: string|null}
     */
    private function tax(array $data, Supplier $supplier): array
    {
        /*
         * Nilai bawaannya mengikuti status PKP supplier. Itu yang membuat pilihan "campuran" bisa
         * dipakai tanpa menuntut orang entry mengingat aturan pajak tiap supplier — ia hanya perlu
         * membantah bawaan ketika memang berbeda.
         */
        $has = array_key_exists('has_tax_invoice', $data) ? (bool) $data['has_tax_invoice'] : $supplier->is_pkp;
        if (! $has) {
            return ['has' => false, 'amount' => BigDecimal::zero(), 'no' => null, 'date' => null];
        }

        $amount = $this->money($data['tax_amount'] ?? '0', 'tax_amount', allowZero: true);
        $no = $this->optional($data['tax_invoice_no'] ?? null, 60);
        if ($no === null) {
            throw new TreasuryException('TAX_INVOICE_NO_REQUIRED',
                'Nomor faktur pajak wajib diisi bila PPN masukan dikreditkan. '
                .'PPN tanpa nomor faktur pajak tidak dapat dikreditkan, jadi mencatatnya sebagai PPN masukan akan keliru.',
                422, field: 'tax_invoice_no');
        }

        $date = $data['tax_invoice_date'] ?? null;

        return [
            'has' => true,
            'amount' => $amount,
            'no' => $no,
            'date' => $date === null || $date === '' ? null : CarbonImmutable::parse((string) $date)->format('Y-m-d'),
        ];
    }

    /**
     * @return list<array{description: string, account_id: string, ingredient_id: string|null,
     *                    quantity: BigDecimal, unit_price: BigDecimal, amount: BigDecimal}>
     */
    private function validateLines(mixed $value): array
    {
        $rows = is_array($value) ? array_values($value) : [];
        if ($rows === []) {
            throw new TreasuryException('INVOICE_EMPTY', 'Faktur harus punya minimal satu baris.', 422, field: 'lines');
        }

        $out = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $account = $this->postableAccount((string) ($row['account_id'] ?? ''), "lines.{$i}.account_id");
            $qty = $this->money($row['quantity'] ?? '1', "lines.{$i}.quantity", allowZero: false, scale: 4);
            $amount = $this->money($row['amount'] ?? '0', "lines.{$i}.amount", allowZero: false);
            $out[] = [
                'description' => $this->text($row['description'] ?? '', 200, "lines.{$i}.description"),
                'account_id' => $account->id,
                'ingredient_id' => isset($row['ingredient_id']) && $row['ingredient_id'] !== ''
                    ? (string) $row['ingredient_id'] : null,
                'quantity' => $qty,
                'unit_price' => $this->money($row['unit_price'] ?? '0', "lines.{$i}.unit_price", allowZero: true, scale: 4),
                'amount' => $amount,
            ];
        }

        if ($out === []) {
            throw new TreasuryException('INVOICE_EMPTY', 'Faktur harus punya minimal satu baris.', 422, field: 'lines');
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
    private function writeLines(PurchaseInvoice $invoice, array $lines): void
    {
        $no = 1;
        foreach ($lines as $line) {
            $row = new PurchaseInvoiceLine;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $invoice->company_id,
                'purchase_invoice_id' => $invoice->id,
                'line_no' => $no++,
                'description' => $line['description'],
                'account_id' => $line['account_id'],
                'ingredient_id' => $line['ingredient_id'],
                'quantity' => (string) $line['quantity'],
                'unit_price' => (string) $line['unit_price'],
                'amount' => (string) $line['amount']->toScale(2),
            ])->save();
        }
    }

    private function assertEditable(PurchaseInvoice $invoice): void
    {
        if ($invoice->isEditable()) {
            return;
        }

        throw new TreasuryException('INVOICE_NOT_EDITABLE',
            'Faktur yang sudah diterbitkan tidak dapat diubah. Koreksinya lewat jurnal balik.',
            409, field: 'status');
    }

    private function supplier(string $id): Supplier
    {
        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->find($id);
        if ($supplier === null) {
            throw new TreasuryException('SUPPLIER_NOT_FOUND', 'Supplier tidak ditemukan.', 422, field: 'supplier_id');
        }

        return $supplier;
    }

    private function goodsReceipt(mixed $id, Supplier $supplier): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        /** @var GoodsReceipt|null $receipt */
        $receipt = GoodsReceipt::query()->find((string) $id);
        if ($receipt === null) {
            throw new TreasuryException('GOODS_RECEIPT_NOT_FOUND',
                'Penerimaan barang tidak ditemukan.', 422, field: 'goods_receipt_id');
        }
        if ($receipt->supplier_id !== null && $receipt->supplier_id !== $supplier->id) {
            throw new TreasuryException('GOODS_RECEIPT_SUPPLIER_MISMATCH',
                'Penerimaan barang itu berasal dari supplier lain.', 422, field: 'goods_receipt_id');
        }

        return $receipt->id;
    }

    private function dueDate(mixed $value, CarbonImmutable $invoiceDate, Supplier $supplier): string
    {
        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        }

        // Kosong berarti "pakai termin supplier"; termin 0 berarti tunai, jatuh tempo hari itu juga.
        return $invoiceDate->addDays(max(0, $supplier->payment_term_days))->format('Y-m-d');
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
