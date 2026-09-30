<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Domain\Models\Order;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Angka total untuk daftar transaksi back-office (permintaan user 30 Sep 2026).
 *
 * Satu kelas dipakai baris total di layar MAUPUN hasil ekspor. Itu bukan kerapian belaka: hari ini
 * juga ditemukan kartu Ringkasan yang menulis 3 sementara daftar yang dibukanya berisi 8, karena
 * angka dan daftarnya dihitung di dua tempat dengan aturan berbeda. Dua pembaca angka yang sama
 * harus memakai satu perhitungan.
 *
 * Dua aturan keuangan di bawah keputusan user, bukan tafsiran sendiri:
 *
 * 1. **Transaksi yang dibatalkan tidak ikut dijumlah.** Barisnya tetap tampil — kasir dan pemeriksa
 *    perlu melihatnya — tetapi nilainya bukan uang yang diterima. Jumlah yang dikecualikan ikut
 *    dilaporkan supaya selisihnya tidak jadi teka-teki.
 * 2. **Retur dikurangkan.** Totalnya karena itu memakai definisi yang sama dengan Laporan
 *    Penjualan, sehingga periode yang sama di dua halaman tidak menghasilkan dua angka.
 *
 * Menerima Query\Builder, bukan Eloquent: itulah yang diserahkan Filament kepada summarizer baris
 * total. Penyaring tenant dan cakupan outlet sudah ikut sebagai SQL di dalamnya, karena kuerinya
 * diturunkan dari kueri Eloquent tabelnya.
 */
final class OrderListSummary
{
    /**
     * @param  Builder  $orders  kueri yang SUDAH tersaring (tanpa halaman)
     * @return array{counted: int, voided: int, gross: string, refund: string, net: string, average: string}
     */
    public function build(Builder $orders): array
    {
        /*
         * `reorder()` wajib: kueri tabel membawa ORDER BY kolom yang tampil, dan Postgres menolak
         * ORDER BY pada kolom yang tidak ikut GROUP BY begitu kuerinya diubah jadi agregat.
         *
         * Kueri aslinya di-clone karena pemanggilnya masih memakainya untuk menampilkan baris.
         */
        $sah = (clone $orders)->reorder()->where('orders.status', '<>', Order::VOIDED);

        $row = (clone $sah)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(orders.total), 0) AS total')
            ->first();

        $counted = (int) ($row->n ?? 0);
        $gross = BigDecimal::of((string) ($row->total ?? '0'));

        $voided = (clone $orders)->reorder()->where('orders.status', Order::VOIDED)->count();

        /*
         * Retur disaring lewat subkueri id transaksi yang ikut dihitung, jadi retur atas transaksi
         * di luar filter — outlet lain, periode lain, atau yang tersisih oleh filter status —
         * tidak ikut mengurangi.
         */
        $refund = BigDecimal::of((string) (DB::table('refunds')
            ->whereIn('refunds.order_id', (clone $sah)->select('orders.id'))
            ->sum('refunds.amount') ?? '0'));

        $net = $gross->minus($refund);

        return [
            'counted' => $counted,
            'voided' => $voided,
            'gross' => self::rupiah($gross),
            'refund' => self::rupiah($refund),
            'net' => self::rupiah($net),
            'average' => $counted === 0
                ? '0.00'
                : self::rupiah($net->dividedBy($counted, 2, RoundingMode::HALF_UP)),
        ];
    }

    private static function rupiah(BigDecimal $value): string
    {
        return (string) $value->toScale(2, RoundingMode::HALF_UP);
    }
}
