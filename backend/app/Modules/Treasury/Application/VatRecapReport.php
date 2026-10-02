<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rekap PPN masukan & keluaran (TAX-02).
 *
 * Disusun dari **dokumen berfaktur pajak**, bukan dari saldo akun. Perbedaannya penting: akun PPN
 * Masukan bisa saja menerima jurnal manual, sedangkan yang boleh dilaporkan ke kantor pajak hanyalah
 * yang punya nomor faktur pajak. Laporan yang dibuat dari saldo akun akan tampak rapi dan tetap salah.
 *
 * Yang **tidak** termasuk di sini: PB1 / pajak restoran dari penjualan kasir. PB1 adalah pajak
 * daerah atas jasa boga, bukan PPN, dan menyatukan keduanya adalah kesalahan yang mahal. Rekapnya
 * sudah ada tersendiri di Laporan → Pajak & Service Charge.
 */
class VatRecapReport
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): ReportTable
    {
        $rows = [];

        $keluaran = BigDecimal::zero();
        $dppKeluaran = BigDecimal::zero();
        /** @var Collection<int, SalesInvoice> $jual */
        $jual = SalesInvoice::query()
            ->whereIn('status', [SalesInvoice::ISSUED, SalesInvoice::PAID])
            ->where('has_tax_invoice', true)
            ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->with('customer:id,name,npwp')
            ->orderBy('invoice_date')->get();

        $rows[] = ['kind' => 'PPN KELUARAN (penjualan ditagih)', 'number' => null, 'date' => null,
            'party' => null, 'npwp' => null, 'dpp' => null, 'vat' => null, '_style' => 'section'];
        foreach ($jual as $inv) {
            $rows[] = [
                'kind' => 'Keluaran',
                'number' => $inv->tax_invoice_no,
                'date' => ($inv->tax_invoice_date ?? $inv->invoice_date)->format('Y-m-d'),
                'party' => $inv->customer->name,
                'npwp' => $inv->customer->npwp,
                'dpp' => (string) BigDecimal::of($inv->subtotal)->toScale(2),
                'vat' => (string) BigDecimal::of($inv->tax_amount)->toScale(2),
            ];
            $dppKeluaran = $dppKeluaran->plus($inv->subtotal);
            $keluaran = $keluaran->plus($inv->tax_amount);
        }
        if ($jual->isEmpty()) {
            $rows[] = ['kind' => 'Tidak ada tagihan berfaktur pajak pada periode ini', 'number' => null,
                'date' => null, 'party' => null, 'npwp' => null, 'dpp' => null, 'vat' => null, '_style' => 'item'];
        }
        $rows[] = ['kind' => 'Jumlah PPN Keluaran', 'number' => null, 'date' => null, 'party' => null,
            'npwp' => null, 'dpp' => (string) $dppKeluaran->toScale(2), 'vat' => (string) $keluaran->toScale(2),
            '_style' => 'subtotal'];

        $masukan = BigDecimal::zero();
        $dppMasukan = BigDecimal::zero();
        /** @var Collection<int, PurchaseInvoice> $beli */
        $beli = PurchaseInvoice::query()
            ->whereIn('status', [PurchaseInvoice::ISSUED, PurchaseInvoice::PAID])
            ->where('has_tax_invoice', true)
            ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->with('supplier:id,name,npwp')
            ->orderBy('invoice_date')->get();

        $rows[] = ['kind' => 'PPN MASUKAN (pembelian berfaktur pajak)', 'number' => null, 'date' => null,
            'party' => null, 'npwp' => null, 'dpp' => null, 'vat' => null, '_style' => 'section'];
        foreach ($beli as $inv) {
            $rows[] = [
                'kind' => 'Masukan',
                'number' => $inv->tax_invoice_no,
                'date' => ($inv->tax_invoice_date ?? $inv->invoice_date)->format('Y-m-d'),
                'party' => $inv->supplier->name,
                'npwp' => $inv->supplier->npwp,
                'dpp' => (string) BigDecimal::of($inv->subtotal)->toScale(2),
                'vat' => (string) BigDecimal::of($inv->tax_amount)->toScale(2),
            ];
            $dppMasukan = $dppMasukan->plus($inv->subtotal);
            $masukan = $masukan->plus($inv->tax_amount);
        }
        if ($beli->isEmpty()) {
            $rows[] = ['kind' => 'Tidak ada faktur pembelian berfaktur pajak pada periode ini', 'number' => null,
                'date' => null, 'party' => null, 'npwp' => null, 'dpp' => null, 'vat' => null, '_style' => 'item'];
        }
        $rows[] = ['kind' => 'Jumlah PPN Masukan', 'number' => null, 'date' => null, 'party' => null,
            'npwp' => null, 'dpp' => (string) $dppMasukan->toScale(2), 'vat' => (string) $masukan->toScale(2),
            '_style' => 'subtotal'];

        $selisih = $keluaran->minus($masukan);
        $rows[] = [
            'kind' => $selisih->isNegative() ? 'LEBIH BAYAR (PPN masukan lebih besar)' : 'PPN YANG KURANG DIBAYAR',
            'number' => null, 'date' => null, 'party' => null, 'npwp' => null, 'dpp' => null,
            'vat' => (string) $selisih->toScale(2), '_style' => 'result',
        ];

        return new ReportTable(
            key: 'rekap-ppn',
            title: 'Rekap PPN Masukan & Keluaran',
            subtitle: $from->translatedFormat('d M Y').' – '.$to->translatedFormat('d M Y'),
            columns: [
                'kind' => ['label' => 'Jenis', 'type' => ReportTable::TEXT],
                'number' => ['label' => 'No. faktur pajak', 'type' => ReportTable::TEXT],
                'date' => ['label' => 'Tanggal', 'type' => ReportTable::TEXT],
                'party' => ['label' => 'Lawan transaksi', 'type' => ReportTable::TEXT],
                'npwp' => ['label' => 'NPWP', 'type' => ReportTable::TEXT],
                'dpp' => ['label' => 'DPP', 'type' => ReportTable::MONEY],
                'vat' => ['label' => 'PPN', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            summary: [
                ['label' => 'PPN Keluaran', 'value' => (string) $keluaran->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'PPN Masukan', 'value' => (string) $masukan->toScale(2), 'type' => ReportTable::MONEY],
                ['label' => 'Selisih', 'value' => (string) $selisih->toScale(2), 'type' => ReportTable::MONEY],
            ],
            notes: [
                'Disusun dari dokumen yang punya nomor faktur pajak, bukan dari saldo akun PPN — '
                .'yang boleh dilaporkan hanyalah yang berfaktur pajak.',
                'PB1 / pajak restoran dari penjualan kasir TIDAK termasuk di sini; ia pajak daerah, bukan PPN. '
                .'Rekapnya ada di Laporan → Pajak & Service Charge.',
                'Laporan ini alat bantu penyusunan SPT, bukan SPT itu sendiri. Pastikan dicocokkan dengan '
                .'bukti faktur pajak sebelum dilaporkan.',
            ],
        );
    }
}
