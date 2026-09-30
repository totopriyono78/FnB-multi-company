<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\EndOfDayService;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Jurnal penjualan harian dari POS sendiri (ACC-11).
 *
 * Satu jurnal per **outlet per hari bisnis** — bukan per struk. Buku besar yang memuat satu baris
 * per transaksi akan berisi ratusan ribu baris sebulan tanpa menambah satu pun keputusan yang bisa
 * diambil darinya; rinciannya tetap dapat ditelusuri lewat Laporan Penjualan.
 *
 * Bentuk jurnalnya (keputusan user 1 Okt 2026):
 *
 * ```
 * Dr  Kas di laci / Piutang settlement   sebesar yang dibayar pelanggan
 * Dr  Biaya MDR                          potongan penyedia pembayaran
 * Dr  Diskon penjualan                   seluruh diskon baris & transaksi
 *     Cr  Pendapatan per kategori menu    nilai kotor sebelum diskon
 *     Cr  Service charge
 *     Cr  PB1 terutang
 *     Cr/Dr Selisih pembulatan
 * ```
 *
 * Tiga hal yang perlu diingat saat membacanya:
 *
 * 1. **MDR diakui saat penjualan**, sehingga piutang settlement yang tercatat = uang yang benar-benar
 *    akan masuk rekening. Rekonsiliasi bank nanti cocok tanpa penyesuaian.
 * 2. **Transaksi yang dibatalkan tidak ikut sama sekali** — bukan dicatat lalu dibalik.
 * 3. **Retur, selisih kas, dan kas masuk/keluar ikut di jurnal yang sama** sebagai blok tersendiri
 *    yang masing-masing seimbang. Menaruhnya di jurnal terpisah membuat satu hari jadi empat
 *    dokumen tanpa alasan.
 *
 * Lahir sebagai **draft**: angka yang salah tidak terlanjur masuk laporan, dan finance yang
 * memutuskan ia benar. Idempoten lewat `source_key` — dipanggil dua kali tetap satu jurnal.
 */
class SalesJournal
{
    public const SOURCE = 'sales';

    public function __construct(
        private readonly EndOfDayService $endOfDay,
        private readonly JournalMap $map,
        private readonly JournalService $journals,
    ) {}

    public static function sourceKey(string $outletId, CarbonImmutable $date): string
    {
        return self::SOURCE.':'.$outletId.':'.$date->format('Y-m-d');
    }

    /** Jurnal yang sudah ada untuk outlet & hari itu, bila ada. */
    public function existing(Outlet $outlet, CarbonImmutable $date): ?Journal
    {
        return Journal::query()->where('source_key', self::sourceKey($outlet->id, $date))->first();
    }

    /**
     * Susun dan simpan jurnal draft untuk satu outlet pada satu hari bisnis.
     *
     * @return Journal|null null bila hari itu benar-benar tidak ada apa-apa untuk dijurnal
     */
    public function build(Outlet $outlet, CarbonImmutable $date, User $actor): ?Journal
    {
        $sudah = $this->existing($outlet, $date);
        if ($sudah !== null) {
            return $sudah;
        }

        $lines = $this->lines($outlet, $date);
        if ($lines === []) {
            return null;
        }

        return $this->journals->create([
            'journal_date' => $date->format('Y-m-d'),
            'description' => 'Penjualan '.$outlet->name.' — '.$date->format('d M Y'),
            'source' => self::SOURCE,
            'source_key' => self::sourceKey($outlet->id, $date),
            'lines' => $lines,
        ], $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(Outlet $outlet, CarbonImmutable $date): array
    {
        $summary = $this->endOfDay->summary($outlet, $date);
        $lines = [];

        // ---------- Blok 1: penjualan ----------
        $gross = BigDecimal::of($summary['gross_total']);          // Σ subtotal, harga jual sebelum diskon
        $discount = BigDecimal::of($summary['discount_total']);
        $service = BigDecimal::of($summary['service_charge_total']);
        $tax = BigDecimal::of($summary['tax_total']);
        $rounding = BigDecimal::of($summary['rounding_total']);
        $billed = BigDecimal::of($summary['sales_total']);         // Σ total = Σ pembayaran (dijamin OrderRecorder)

        /*
         * Pajak yang sudah TERKANDUNG di dalam harga jual.
         *
         * Outlet boleh memasang harga sudah termasuk pajak (`tax_inclusive`). Bila begitu,
         * `subtotal` memuat PB1 di dalamnya, sementara PB1 itu juga dikreditkan sendiri ke akun
         * utang pajak — mengkredit pendapatan sebesar subtotal berarti menghitung pajaknya dua kali
         * dan jurnalnya tidak akan pernah seimbang.
         *
         * Nilainya tidak ditebak dari tarif pajak, melainkan disimpulkan dari identitas yang pasti
         * berlaku di kedua mode:
         *
         *     total tagihan = subtotal − diskon − pajak_terkandung + service charge + pajak + pembulatan
         *
         * sehingga pajak_terkandung = (subtotal − diskon + SC + pajak + pembulatan) − total tagihan.
         * Pada harga belum termasuk pajak hasilnya nol dan blok ini berperilaku persis seperti
         * sebelumnya. Cara ini juga kebal terhadap perubahan tarif di tengah hari: ia membaca apa
         * yang benar-benar tercatat, bukan apa yang seharusnya.
         */
        $embedded = $gross->minus($discount)->plus($service)->plus($tax)->plus($rounding)->minus($billed);
        $revenue = $gross->minus($embedded);

        foreach ($this->allocate($revenue, $this->revenueByCategory($outlet, $date)) as $categoryId => $amount) {
            $this->add($lines, $outlet, $this->map->account(JournalMap::REVENUE, $categoryId === '' ? null : $categoryId)->id,
                credit: $amount, memo: 'Penjualan');
        }
        $this->add($lines, $outlet, $this->map->account(JournalMap::SERVICE_CHARGE)->id,
            credit: (string) $service, memo: 'Service charge');
        $this->add($lines, $outlet, $this->map->account(JournalMap::TAX)->id,
            credit: (string) $tax, memo: 'Pajak keluaran');
        $this->add($lines, $outlet, $this->map->account(JournalMap::DISCOUNT)->id,
            debit: (string) $discount, memo: 'Diskon penjualan');

        // Pembulatan bisa menambah atau mengurangi tagihan; arahnya mengikuti tandanya.
        $this->add($lines, $outlet, $this->map->account(JournalMap::ROUNDING)->id,
            debit: $rounding->isNegative() ? (string) $rounding->abs() : '0',
            credit: $rounding->isPositive() ? (string) $rounding : '0',
            memo: 'Selisih pembulatan');

        foreach ($summary['payments'] as $method => $row) {
            $amount = BigDecimal::of($row['amount']);
            $mdr = BigDecimal::of($row['mdr']);
            // Piutang settlement dicatat BERSIH: yang tercatat = uang yang akan benar-benar masuk.
            $this->add($lines, $outlet, $this->map->account(JournalMap::paymentSlot((string) $method))->id,
                debit: (string) $amount->minus($mdr), memo: 'Pembayaran '.$method);
            $this->add($lines, $outlet, $this->map->account(JournalMap::MDR)->id,
                debit: (string) $mdr, memo: 'Biaya transaksi '.$method);
        }

        /*
         * ---------- Blok 2: retur ----------
         *
         * Seluruh nilai yang dikembalikan masuk ke akun Retur Penjualan, termasuk bagian pajak dan
         * service charge-nya. Konsekuensinya PB1 terutang TIDAK ikut berkurang saat ada retur.
         * Apakah PB1 atas transaksi yang diretur boleh dikurangkan — dan dengan cara apa — adalah
         * aturan perpajakan daerah, bukan hal yang boleh disimpulkan dari kode. Sampai aturannya
         * dipastikan, retur dicatat utuh di satu akun agar besarnya terlihat jelas dan koreksinya
         * bisa dilakukan sekali di akhir periode, bukan tersebar diam-diam di tiap hari.
         */
        foreach ($this->refundsByMethod($outlet, $date) as $method => $amount) {
            $this->add($lines, $outlet, $this->map->account(JournalMap::REFUND)->id,
                debit: $amount, memo: 'Retur penjualan');
            $this->add($lines, $outlet, $this->map->account(JournalMap::paymentSlot((string) $method))->id,
                credit: $amount, memo: 'Pengembalian dana '.$method);
        }

        // ---------- Blok 3: selisih kas ----------
        $variance = BigDecimal::of($summary['cash_variance_total']);
        if (! $variance->isZero()) {
            $kas = $this->map->account(JournalMap::paymentSlot('cash'))->id;
            $selisih = $this->map->account(JournalMap::CASH_VARIANCE)->id;
            // Negatif = uang di laci kurang dari seharusnya.
            $kurang = $variance->isNegative();
            $nilai = (string) $variance->abs();
            $this->add($lines, $outlet, $kurang ? $selisih : $kas, debit: $nilai, memo: 'Selisih kas');
            $this->add($lines, $outlet, $kurang ? $kas : $selisih, credit: $nilai, memo: 'Selisih kas');
        }

        // ---------- Blok 4: kas masuk/keluar shift ----------
        $movements = $this->cashMovements($outlet, $date);
        $kasSlot = $this->map->account(JournalMap::paymentSlot('cash'))->id;
        $penampung = $this->map->account(JournalMap::CASH_MOVEMENT)->id;
        if (isset($movements['in'])) {
            $this->add($lines, $outlet, $kasSlot, debit: $movements['in'], memo: 'Kas masuk shift');
            $this->add($lines, $outlet, $penampung, credit: $movements['in'], memo: 'Kas masuk shift — perlu diklasifikasi');
        }
        if (isset($movements['out'])) {
            $this->add($lines, $outlet, $penampung, debit: $movements['out'], memo: 'Kas keluar shift — perlu diklasifikasi');
            $this->add($lines, $outlet, $kasSlot, credit: $movements['out'], memo: 'Kas keluar shift');
        }

        $lines = $this->merge($lines);
        $this->assertBalanced($lines, $outlet, $date);

        return $lines;
    }

    /**
     * Bagi satu nilai menurut bobot, dengan jumlah yang dijamin persis sama dengan nilai aslinya.
     *
     * Pembagian proporsial yang tiap potongnya dibulatkan sendiri hampir selalu meleset satu-dua
     * sen dari nilai asal. Selisih itu ditaruh pada bobot terbesar — di sanalah ia paling kecil
     * secara relatif — sehingga jurnalnya tetap seimbang tanpa baris "pembulatan" yang mengada-ada.
     *
     * @param  array<string, string>  $weights
     * @return array<string, string>
     */
    private function allocate(BigDecimal $total, array $weights): array
    {
        if ($total->isZero()) {
            return [];
        }
        $sum = array_reduce($weights, fn (BigDecimal $c, string $w) => $c->plus($w), BigDecimal::zero());
        if (! $sum->isPositive()) {
            // Ada nilai penjualan tetapi tidak ada baris item yang bisa dikelompokkan: seluruhnya
            // ke pemetaan pendapatan bawaan, bukan hilang.
            return ['' => (string) $total->toScale(2)];
        }

        $shares = [];
        $allocated = BigDecimal::zero();
        $biggest = null;
        foreach ($weights as $key => $weight) {
            $share = $total->multipliedBy($weight)->dividedBy($sum, 2, RoundingMode::HALF_UP);
            $shares[$key] = $share;
            $allocated = $allocated->plus($share);
            if ($biggest === null || BigDecimal::of($weight)->isGreaterThan(BigDecimal::of($weights[$biggest]))) {
                $biggest = $key;
            }
        }
        $shares[$biggest] = $shares[$biggest]->plus($total->minus($allocated));

        return array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $shares);
    }

    /**
     * Jaring pengaman terakhir sebelum jurnalnya diserahkan ke JournalService.
     *
     * JournalService juga menolak jurnal yang tidak seimbang, tetapi pesannya bicara tentang jurnal;
     * di sini yang salah hampir pasti datanya di POS, dan itulah yang perlu diberitahukan agar
     * orang mencarinya di tempat yang benar.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertBalanced(array $lines, Outlet $outlet, CarbonImmutable $date): void
    {
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();
        foreach ($lines as $line) {
            $debit = $debit->plus($line['debit']);
            $credit = $credit->plus($line['credit']);
        }
        if ($debit->isEqualTo($credit)) {
            return;
        }

        throw new AccountingException('SALES_JOURNAL_UNBALANCED',
            'Jurnal penjualan '.$outlet->name.' '.$date->format('d M Y').' tidak seimbang (selisih '
            .(string) $debit->minus($credit)->abs()->toScale(2).'). Ini menandakan data transaksi hari itu '
            .'tidak konsisten — periksa Laporan Penjualan hari tersebut sebelum menjurnal.',
            422, details: ['debit' => (string) $debit->toScale(2), 'credit' => (string) $credit->toScale(2)]);
    }

    /**
     * Pendapatan kotor per kategori menu. Kunci '' = baris tanpa kategori.
     *
     * Memakai `gross` (sebelum diskon) karena diskonnya dicatat terpisah sebagai akun lawan —
     * itulah yang membuat besarnya diskon terlihat di laporan, bukan tersembunyi di pendapatan.
     *
     * @return array<string, string>
     */
    private function revenueByCategory(Outlet $outlet, CarbonImmutable $date): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', function ($join): void {
                $join->on('o.id', '=', 'oi.order_id')->on('o.business_date', '=', 'oi.business_date');
            })
            ->where('oi.company_id', $outlet->company_id)
            ->where('o.outlet_id', $outlet->id)
            ->where('oi.business_date', $date->format('Y-m-d'))
            ->where('o.status', '<>', 'voided')
            ->where('oi.status', 'sold')
            ->groupBy('oi.category_id')
            ->selectRaw('oi.category_id, coalesce(sum(oi.gross), 0) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->category_id ?? '') => (string) $r->amount])
            ->all();
    }

    /** @return array<string, string> */
    private function refundsByMethod(Outlet $outlet, CarbonImmutable $date): array
    {
        return DB::table('refunds')
            ->where('company_id', $outlet->company_id)
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $date->format('Y-m-d'))
            ->groupBy('method')
            ->selectRaw('method, coalesce(sum(amount), 0) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->method => (string) $r->amount])
            ->all();
    }

    /** @return array<string, string> */
    private function cashMovements(Outlet $outlet, CarbonImmutable $date): array
    {
        return DB::table('cash_movements')
            ->where('company_id', $outlet->company_id)
            ->where('outlet_id', $outlet->id)
            ->where('business_date', $date->format('Y-m-d'))
            ->whereIn('type', ['in', 'out'])
            ->groupBy('type')
            ->selectRaw('type, coalesce(sum(amount), 0) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->type => (string) $r->amount])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function add(array &$lines, Outlet $outlet, string $accountId, string $debit = '0', string $credit = '0', string $memo = ''): void
    {
        // Baris bernilai nol tidak ditulis: ia hanya menambah panjang jurnal tanpa menambah arti,
        // dan CHECK constraint di basis data pun menolaknya.
        if (BigDecimal::of($debit)->isZero() && BigDecimal::of($credit)->isZero()) {
            return;
        }
        $lines[] = [
            'account_id' => $accountId,
            'debit' => (string) BigDecimal::of($debit)->toScale(2),
            'credit' => (string) BigDecimal::of($credit)->toScale(2),
            'memo' => $memo,
            'outlet_id' => $outlet->id,
            'brand_id' => $outlet->brand_id,
        ];
    }

    /**
     * Gabungkan baris yang akunnya sama DAN sisinya sama.
     *
     * Kartu debit dan kredit sering memetakan ke akun piutang settlement yang sama; tanpa
     * penggabungan, jurnalnya memuat dua baris identik yang membingungkan pembaca tanpa menambah
     * keterangan apa pun.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function merge(array $lines): array
    {
        $byKey = [];
        foreach ($lines as $line) {
            $sisi = BigDecimal::of($line['debit'])->isPositive() ? 'd' : 'k';
            $key = $line['account_id'].'|'.$sisi;
            if (! isset($byKey[$key])) {
                $byKey[$key] = $line;

                continue;
            }
            $byKey[$key]['debit'] = (string) BigDecimal::of($byKey[$key]['debit'])->plus($line['debit'])->toScale(2);
            $byKey[$key]['credit'] = (string) BigDecimal::of($byKey[$key]['credit'])->plus($line['credit'])->toScale(2);
        }

        return array_values($byKey);
    }
}
