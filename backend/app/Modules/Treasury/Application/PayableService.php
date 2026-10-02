<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Domain\Models\PaymentRequestInvoice;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use App\Modules\Treasury\Domain\Models\PurchaseInvoicePayment;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hutang usaha: menyambungkan faktur pembelian dengan SPPK → advis bayar (AP-03, AP-04).
 *
 * Pembayaran supplier **tidak** mendapat jalur sendiri. Ia memakai jalur persetujuan yang sudah ada:
 * SPPK menunjuk akun Utang Usaha dan menyebut faktur mana yang hendak dilunasi; ketika advis bayar
 * terbit, jurnal yang sama persis — Dr akun SPPK / Cr bank — berubah arti dari "mengakui beban"
 * menjadi "melunasi hutang", dan alokasinya dicatat di sini.
 *
 * Alokasinya **urut jatuh tempo terdekat**. Itu bukan sekadar pilihan teknis: supplier menagih per
 * faktur, dan pembayaran sebagian yang tidak jelas melunasi faktur mana akan menimbulkan perselisihan
 * yang tidak bisa diselesaikan dari kedua sisi.
 */
class PayableService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Tetapkan faktur mana saja yang hendak dilunasi sebuah SPPK.
     *
     * @param  array<string, string>  $allocations  faktur id => nilai
     */
    public function attachInvoices(PaymentRequest $request, array $allocations): void
    {
        DB::transaction(function () use ($request, $allocations): void {
            PaymentRequestInvoice::query()->where('payment_request_id', $request->id)->delete();

            foreach ($allocations as $invoiceId => $amount) {
                $nilai = BigDecimal::of((string) $amount);
                if ($nilai->isNegativeOrZero()) {
                    continue;
                }
                /** @var PurchaseInvoice|null $invoice */
                $invoice = PurchaseInvoice::query()->find($invoiceId);
                if ($invoice === null) {
                    throw new TreasuryException('INVOICE_NOT_FOUND', 'Faktur tidak ditemukan.', 422, field: 'invoices');
                }
                if ($invoice->status !== PurchaseInvoice::ISSUED) {
                    throw new TreasuryException('INVOICE_NOT_PAYABLE',
                        "Faktur {$invoice->number} belum diterbitkan atau sudah lunas, jadi belum ada hutang untuk dibayar.",
                        422, field: 'invoices');
                }
                if ($nilai->isGreaterThan($invoice->outstanding())) {
                    throw new TreasuryException('INVOICE_OVERPAY',
                        "Nilai untuk faktur {$invoice->number} melebihi sisa hutangnya (".$invoice->outstanding()->toScale(2).').',
                        422, field: 'invoices');
                }

                $row = new PaymentRequestInvoice;
                $row->forceFill([
                    'id' => (string) Str::uuid7(),
                    'company_id' => $request->company_id,
                    'payment_request_id' => $request->id,
                    'purchase_invoice_id' => $invoice->id,
                    'amount' => (string) $nilai->toScale(2),
                ])->save();
            }
        });
    }

    /**
     * Alokasikan satu advis bayar ke faktur-faktur yang ditunjuk SPPK-nya.
     *
     * Dipanggil setelah advis terbit. Bila SPPK tidak menunjuk faktur apa pun — pembayaran langsung
     * tanpa faktur — tidak ada yang dialokasikan, dan itu keadaan yang sah.
     */
    public function allocateAdvice(PaymentAdvice $advice): void
    {
        DB::transaction(function () use ($advice): void {
            /** @var list<PaymentRequestInvoice> $tujuan */
            $tujuan = PaymentRequestInvoice::query()
                ->where('payment_request_id', $advice->payment_request_id)
                ->with('invoice')
                ->get()
                ->sortBy(fn (PaymentRequestInvoice $r) => $r->invoice?->due_date?->format('Y-m-d') ?? '9999-12-31')
                ->values()
                ->all();

            if ($tujuan === []) {
                return;
            }

            $sisa = BigDecimal::of($advice->amount);
            foreach ($tujuan as $baris) {
                if ($sisa->isNegativeOrZero()) {
                    break;
                }
                /** @var PurchaseInvoice $invoice */
                $invoice = PurchaseInvoice::query()->whereKey($baris->purchase_invoice_id)->lockForUpdate()->firstOrFail();

                // Yang dialokasikan: sekecil-kecilnya di antara sisa advis, rencana SPPK, dan sisa hutang.
                $porsi = $this->min($sisa, BigDecimal::of($baris->amount), $invoice->outstanding());
                if ($porsi->isNegativeOrZero()) {
                    continue;
                }

                $bayar = new PurchaseInvoicePayment;
                $bayar->forceFill([
                    'id' => (string) Str::uuid7(),
                    'company_id' => $invoice->company_id,
                    'purchase_invoice_id' => $invoice->id,
                    'payment_advice_id' => $advice->id,
                    'amount' => (string) $porsi->toScale(2),
                    'paid_on' => $advice->paid_on->format('Y-m-d'),
                ])->save();

                $terbayar = BigDecimal::of($invoice->paid_amount)->plus($porsi);
                $invoice->forceFill([
                    'paid_amount' => (string) $terbayar->toScale(2),
                    'status' => $terbayar->isGreaterThanOrEqualTo($invoice->total)
                        ? PurchaseInvoice::PAID : $invoice->status,
                ])->save();

                $sisa = $sisa->minus($porsi);
            }

            $this->audit->log('payable.allocated', $advice, new: [
                'advice' => $advice->number, 'invoices' => count($tujuan),
                'unallocated' => (string) $sisa->toScale(2),
            ]);
        });
    }

    /**
     * Umur hutang (AP-04): sisa hutang per supplier, dikelompokkan menurut lama tertunggak.
     *
     * Ember umurnya mengikuti kebiasaan yang sudah lazim dibaca pemilik usaha — belum jatuh tempo,
     * 1–30, 31–60, 61–90, di atas 90 — sehingga laporannya bisa langsung dibandingkan dengan yang
     * mereka terima dari akuntan sebelumnya.
     */
    public function aging(?CarbonImmutable $asOf = null): ReportTable
    {
        $per = $asOf ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();

        /** @var iterable<PurchaseInvoice> $invoices */
        $invoices = PurchaseInvoice::query()
            ->whereIn('status', [PurchaseInvoice::ISSUED, PurchaseInvoice::PAID])
            ->where('invoice_date', '<=', $per->format('Y-m-d'))
            ->with('supplier:id,name')
            ->get();

        $ember = ['belum' => 'not_due', 'b1' => 'd1_30', 'b2' => 'd31_60', 'b3' => 'd61_90', 'b4' => 'd90_plus'];
        $per_supplier = [];
        $totals = array_fill_keys(array_values($ember) + ['total' => 'total'], BigDecimal::zero());
        $totals['total'] = BigDecimal::zero();

        foreach ($invoices as $invoice) {
            $sisa = $invoice->outstanding();
            if ($sisa->isNegativeOrZero()) {
                continue;
            }
            $nama = $invoice->supplier->name;
            $per_supplier[$nama] ??= array_fill_keys(array_values($ember), BigDecimal::zero())
                + ['total' => BigDecimal::zero()];

            $umur = $invoice->daysOverdue($per);
            $kolom = match (true) {
                $umur <= 0 => 'not_due',
                $umur <= 30 => 'd1_30',
                $umur <= 60 => 'd31_60',
                $umur <= 90 => 'd61_90',
                default => 'd90_plus',
            };

            $per_supplier[$nama][$kolom] = $per_supplier[$nama][$kolom]->plus($sisa);
            $per_supplier[$nama]['total'] = $per_supplier[$nama]['total']->plus($sisa);
            $totals[$kolom] = $totals[$kolom]->plus($sisa);
            $totals['total'] = $totals['total']->plus($sisa);
        }

        ksort($per_supplier);
        $rows = [];
        foreach ($per_supplier as $nama => $nilai) {
            $rows[] = ['supplier' => $nama] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $nilai);
        }

        return new ReportTable(
            key: 'umur-hutang',
            title: 'Umur Hutang Usaha',
            subtitle: 'Sisa hutang per '.$per->translatedFormat('d M Y'),
            columns: [
                'supplier' => ['label' => 'Supplier', 'type' => ReportTable::TEXT],
                'not_due' => ['label' => 'Belum jatuh tempo', 'type' => ReportTable::MONEY],
                'd1_30' => ['label' => '1–30 hari', 'type' => ReportTable::MONEY],
                'd31_60' => ['label' => '31–60 hari', 'type' => ReportTable::MONEY],
                'd61_90' => ['label' => '61–90 hari', 'type' => ReportTable::MONEY],
                'd90_plus' => ['label' => '> 90 hari', 'type' => ReportTable::MONEY],
                'total' => ['label' => 'Jumlah', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['supplier' => 'JUMLAH'] + array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $totals),
            notes: [
                'Faktur tanpa tanggal jatuh tempo dihitung jatuh tempo pada tanggal fakturnya — tagihan tanpa tenggat tetap tagihan.',
                'Jumlah di laporan ini seharusnya sama dengan saldo akun Utang Usaha di Neraca pada tanggal yang sama; '
                .'selisih berarti ada jurnal ke Utang Usaha yang tidak lewat faktur pembelian.',
            ],
        );
    }

    private function min(BigDecimal ...$values): BigDecimal
    {
        $min = $values[0];
        foreach ($values as $v) {
            if ($v->isLessThan($min)) {
                $min = $v;
            }
        }

        return $min;
    }
}
