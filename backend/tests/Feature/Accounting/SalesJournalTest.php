<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalMap;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Catalog\Domain\Pricing\PricingCalculator;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Jurnal penjualan otomatis (ACC-10, ACC-11).
 *
 * Yang diuji di sini bukan "jurnalnya terbentuk", melainkan janji yang membuatnya berguna:
 * angkanya sama persis dengan ringkasan Tutup Hari, debitnya sama dengan kreditnya sampai sen
 * terakhir, satu hari tidak pernah dijurnal dua kali, dan kegagalan pembukuan tidak pernah
 * menahan kasir menutup hari.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    $this->owner = Factory::ownerOf($this->pos->company);
    $this->headers = asMember($this->owner, $this->pos->company);
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '100000');
    $this->date = $this->pos->tenant(fn () => Shift::query()->find($this->shiftId)->business_date->format('Y-m-d'));
});

afterEach(fn () => app(TenantContext::class)->reset());

/** Pasang bagan akun + pemetaan bawaan sehingga jurnal otomatis punya tempat mendarat. */
function siapkanPembukuan(object $test): void
{
    $test->pos->tenant(function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(JournalMap::class)->installDefaults();
    });
}

/** Tutup shift lalu tutup hari bisnis lewat API back-office, persis seperti di lapangan. */
function tutupHariBisnis(object $test, string $countedCash): void
{
    $test->pos->push('shift.close', [
        'shift_id' => $test->shiftId,
        'closed_by' => $test->pos->userId('cashier'),
        'closed_at' => now()->toIso8601String(),
        'counted_cash' => $countedCash,
        'variance_note' => 'Dihitung ulang saat tutup shift',
    ]);
    $test->postJson("/api/v1/outlets/{$test->pos->outlet->id}/end-of-day", ['business_date' => $test->date], $test->headers)
        ->assertCreated();
}

/** @return array<string, array{debit: string, credit: string}> saldo per kode akun */
function barisJurnalPenjualan(object $test): array
{
    return $test->pos->tenant(function () use ($test): array {
        $jurnal = Journal::query()->where('source', 'sales')->sole();
        expect($jurnal->source_key)->toBe('sales:'.$test->pos->outlet->id.':'.$test->date)
            ->and($jurnal->status)->toBe(Journal::DRAFT);

        $rows = [];
        foreach (JournalLine::query()->where('journal_id', $jurnal->id)->with('account')->get() as $line) {
            $rows[$line->account->code] = ['debit' => $line->debit, 'credit' => $line->credit];
        }

        return $rows;
    });
}

it('menyusun jurnal draft yang angkanya sama dengan ringkasan Tutup Hari', function () {
    siapkanPembukuan($this);
    // Pesanan standar: subtotal 68.000, SC 3.400, PB1 7.140, pembulatan −40, total 78.500 tunai.
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    // Laci kurang 500 dari yang seharusnya (100.000 + 78.500).
    tutupHariBisnis($this, '178000');

    $baris = barisJurnalPenjualan($this);

    expect($baris['4103'])->toBe(['debit' => '0.00', 'credit' => '68000.00'])   // pendapatan (kategori belum dipetakan)
        ->and($baris['4201'])->toBe(['debit' => '0.00', 'credit' => '3400.00']) // service charge
        ->and($baris['2201'])->toBe(['debit' => '0.00', 'credit' => '7140.00']) // PB1 terutang
        ->and($baris['4303'])->toBe(['debit' => '40.00', 'credit' => '0.00'])   // pembulatan mengurangi tagihan
        ->and($baris['6106'])->toBe(['debit' => '500.00', 'credit' => '0.00']); // selisih kas (laci kurang)

    // Kas: 78.500 masuk dari penjualan, 500 keluar sebagai selisih laci.
    expect($baris)->not->toHaveKey('4301'); // tidak ada diskon → tidak ada baris nol yang mengotori jurnal

    $kas = $this->pos->tenant(fn () => JournalLine::query()
        ->whereHas('account', fn ($q) => $q->where('code', '1101'))
        ->get());
    expect((string) $kas->sum(fn ($l) => (float) $l->debit))->toBe('78500')
        ->and((string) $kas->sum(fn ($l) => (float) $l->credit))->toBe('500');
});

it('debit selalu sama dengan kredit, termasuk saat harga sudah termasuk pajak', function (bool $inclusive) {
    // Harga termasuk pajak: subtotal sudah memuat PB1, sehingga pendapatan tidak boleh dikredit penuh.
    $this->pos->tenant(fn () => Outlet::query()->whereKey($this->pos->outlet->id)->update(['tax_inclusive' => $inclusive]));
    $this->pos->backdate();
    siapkanPembukuan($this);

    $order = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1));
    $order['pricing']['tax_inclusive'] = $inclusive;
    /*
     * Totalnya dihitung dengan mesin harga yang sama dengan POS, bukan diketik dari perhitungan
     * tangan: yang diuji di sini adalah jurnalnya, dan angka acuan yang dikarang sendiri hanya akan
     * menguji ulang aritmatika penulis tesnya.
     */
    $hitung = app(PricingCalculator::class)->calculate([
        'config' => $order['pricing'],
        'lines' => [
            ['id' => $order['lines'][0]['id'], 'unit_price' => '25000', 'qty' => 2, 'sold_by_weight' => false, 'modifiers' => [], 'discounts' => []],
            ['id' => $order['lines'][1]['id'], 'unit_price' => '18000', 'qty' => 1, 'sold_by_weight' => false, 'modifiers' => [], 'discounts' => []],
        ],
        'order_discounts' => [],
    ])['totals'];
    $order['totals'] = array_intersect_key($hitung, array_flip(['subtotal', 'item_discount', 'order_discount', 'service_charge', 'tax', 'rounding', 'total']));
    $order['payments'][0]['amount'] = $hitung['total'];

    expect($this->pos->push('order', $order)['status'])->toBe('accepted');

    tutupHariBisnis($this, (string) BigDecimal::of('100000')->plus($hitung['total']));

    $this->pos->tenant(function () use ($inclusive): void {
        $jurnal = Journal::query()->where('source', 'sales')->sole();
        $lines = JournalLine::query()->where('journal_id', $jurnal->id)->with('account')->get();
        $debit = $lines->reduce(fn (BigDecimal $c, $l) => $c->plus($l->debit), BigDecimal::zero());
        $credit = $lines->reduce(fn (BigDecimal $c, $l) => $c->plus($l->credit), BigDecimal::zero());

        expect((string) $debit)->toBe((string) $credit);

        // Pada harga termasuk pajak, pendapatan yang dikreditkan lebih kecil dari subtotal —
        // selisihnya persis PB1 yang sudah terkandung di dalam harga.
        $pendapatan = $lines->first(fn ($l) => $l->account->code === '4103');
        expect(BigDecimal::of($pendapatan->credit)->isLessThan('68000'))->toBe($inclusive);
    });
})->with([false, true]);

it('tidak menjurnal transaksi yang dibatalkan', function () {
    siapkanPembukuan($this);
    $dibatalkan = $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1), ['status' => 'voided', 'payments' => [], 'voided_by' => $this->pos->userId('manager'), 'void_reason' => 'Salah input']);
    $this->pos->push('order', $dibatalkan);
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 2)));

    tutupHariBisnis($this, '178500');

    $baris = barisJurnalPenjualan($this);
    // Satu transaksi saja yang masuk, bukan dua lalu dibalik.
    expect($baris['4103']['credit'])->toBe('68000.00');
});

it('satu hari hanya punya satu jurnal, berapa kali pun disusun ulang', function () {
    siapkanPembukuan($this);
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    tutupHariBisnis($this, '178500');

    $nomor = $this->pos->tenant(fn () => Journal::query()->where('source', 'sales')->sole()->number);

    $this->artisan('akuntansi:jurnal-penjualan', ['--company' => $this->pos->company->id])
        ->expectsOutputToContain('Jurnal baru dibuat: 0')
        ->assertSuccessful();

    $this->pos->tenant(function () use ($nomor): void {
        expect(Journal::query()->where('source', 'sales')->count())->toBe(1)
            ->and(Journal::query()->where('source', 'sales')->sole()->number)->toBe($nomor);
    });
});

it('pembukuan yang belum siap tidak menggagalkan Tutup Hari, dan bisa disusul kemudian', function () {
    // Sengaja TIDAK memasang bagan akun: entitas yang baru mulai berjualan sebelum menata pembukuan.
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));

    tutupHariBisnis($this, '178500'); // tetap berhasil — inilah janjinya

    $this->pos->tenant(function (): void {
        expect(Journal::query()->count())->toBe(0);
        $catatan = AuditLog::query()->where('action', 'journal.sales_skipped')->sole();
        expect($catatan->new_values['code'])->toBe('MAPPING_MISSING');
    });

    // Finance menata pembukuannya, lalu hari yang terlewat disusul.
    siapkanPembukuan($this);
    $this->artisan('akuntansi:jurnal-penjualan', ['--company' => $this->pos->company->id])
        ->expectsOutputToContain('Jurnal baru dibuat: 1')
        ->assertSuccessful();

    expect(barisJurnalPenjualan($this)['4103']['credit'])->toBe('68000.00');
});

it('isolasi tenant: jurnal penjualan satu entitas tidak terlihat dari entitas lain', function () {
    siapkanPembukuan($this);
    $this->pos->push('order', $this->pos->order($this->shiftId, $this->pos->receipt($this->ymd, 1)));
    tutupHariBisnis($this, '178500');

    [$lain] = Factory::company('Warung Bu Ratna');
    Factory::tenant($lain, function (): void {
        expect(Journal::query()->where('source', 'sales')->count())->toBe(0)
            ->and(DB::table('journal_lines')->count())->toBe(0);
    });
});
