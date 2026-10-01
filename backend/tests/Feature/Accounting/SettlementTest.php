<?php

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Application\JournalMap;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Application\SettlementService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Tests\Support\Factory;
use Tests\Support\Pos;

/**
 * Pencairan settlement (ACC-12).
 *
 * Janji yang dijaga di sini: **piutang settlement benar-benar bisa habis**. Sebelum modul ini ada,
 * jurnal penjualan hanya pernah menambah piutang itu dan tidak pernah menguranginya — cacat yang
 * baru terlihat sebulan kemudian, saat Neraca menampilkan piutang yang tidak pernah ada.
 */
beforeEach(function () {
    $this->pos = Pos::setup();
    $this->owner = Factory::ownerOf($this->pos->company);
    $this->headers = asMember($this->owner, $this->pos->company);
    [$this->pemeriksa] = Factory::staff($this->pos->company, ['finance'], []);
    [$this->shiftId, $this->ymd] = $this->pos->openShift('cashier', '100000');
    $this->date = $this->pos->tenant(fn () => Shift::query()->find($this->shiftId)->business_date->format('Y-m-d'));

    $this->pos->tenant(function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(JournalMap::class)->installDefaults();
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

/** Satu transaksi kartu debit Rp78.500 (pesanan standar, pembayaran diganti metodenya). */
function jualKartu(object $test, int $seq = 1): void
{
    $order = $test->pos->order($test->shiftId, $test->pos->receipt($test->ymd, $seq));
    $order['payments'][0] = ['id' => (string) Str::uuid7(), 'method' => 'debit',
        'amount' => '78500', 'created_at' => now()->subMinutes(4)->toIso8601String()];
    expect($test->pos->push('order', $order)['status'])->toBe('accepted');
}

/** Tutup shift + tutup hari, lalu ajukan & posting jurnal penjualan yang lahir dari situ. */
function bukukanHari(object $test): void
{
    $test->pos->push('shift.close', [
        'shift_id' => $test->shiftId, 'closed_by' => $test->pos->userId('cashier'),
        'closed_at' => now()->toIso8601String(), 'counted_cash' => '100000',
        'variance_note' => 'Tidak ada transaksi tunai',
    ]);
    test()->postJson("/api/v1/outlets/{$test->pos->outlet->id}/end-of-day", ['business_date' => $test->date], $test->headers)
        ->assertCreated();

    $test->pos->tenant(function () use ($test): void {
        $service = app(JournalService::class);
        $jurnal = Journal::query()->where('source', 'sales')->sole();
        $service->post($service->submit($jurnal, $test->owner), $test->pemeriksa);
    });
}

it('menghitung sisa piutang settlement dari pembayaran non-tunai', function () {
    jualKartu($this);
    bukukanHari($this);

    $this->pos->tenant(function (): void {
        $laporan = app(SettlementService::class)->outstanding(CarbonImmutable::parse($this->date));
        $debit = collect($laporan->rows)->firstWhere('method', 'Kartu Debit');

        expect($debit['sales'])->toBe('78500.00')
            ->and($debit['settled'])->toBe('0.00')
            ->and($debit['outstanding'])->toBe('78500.00');
    });
});

it('pencairan membersihkan piutang dan angkanya cocok dengan Neraca', function () {
    jualKartu($this);
    bukukanHari($this);

    $this->pos->tenant(function (): void {
        $bank = Account::query()->where('code', '1110')->firstOrFail();

        // Penyedia memotong Rp1.500 saat mencairkan; sisanya masuk rekening.
        $batch = app(SettlementService::class)->record([
            'method' => 'debit',
            'settled_on' => $this->date,
            'gross_amount' => '78500',
            'fee_amount' => '1500',
            'bank_account_id' => $bank->id,
            'reference' => 'STL-0001',
        ], $this->owner);

        expect((string) $batch->net_amount)->toBe('77000.00');

        // Selama jurnalnya belum diposting, piutangnya BELUM berkurang — laporan mengatakannya.
        $sebelum = app(SettlementService::class)->outstanding(CarbonImmutable::parse($this->date));
        $debit = collect($sebelum->rows)->firstWhere('method', 'Kartu Debit');
        expect($debit['outstanding'])->toBe('78500.00')->and($debit['pending'])->toBe('78500.00');

        $service = app(JournalService::class);
        $jurnal = Journal::query()->where('source', 'settlement')->sole();
        $service->post($service->submit($jurnal, $this->owner), $this->pemeriksa);

        // Setelah diposting: piutang habis.
        $sesudah = app(SettlementService::class)->outstanding(CarbonImmutable::parse($this->date));
        expect(collect($sesudah->rows)->firstWhere('method', 'Kartu Debit')['outstanding'])->toBe('0.00');

        /*
         * Dan yang paling penting: laporan ini tidak boleh punya kebenarannya sendiri. Saldo akun
         * Piutang Settlement Kartu di Neraca harus mengatakan hal yang sama.
         */
        $neraca = app(FinancialStatements::class)->balanceSheet(
            CarbonImmutable::parse($this->date)->subDay(), CarbonImmutable::parse($this->date));
        $baris = collect($neraca->rows)->firstWhere('name', '1201 — Piutang Settlement Kartu');
        expect($baris)->toBeNull(); // saldo nol tidak ditampilkan — itulah arti "habis"

        $bankRow = collect($neraca->rows)->firstWhere('name', '1110 — Bank');
        expect($bankRow['amount'])->toBe('77000.00');
    });
});

it('menolak pencairan melebihi potongan, metode tunai, dan nomor settlement ganda', function () {
    jualKartu($this);
    bukukanHari($this);

    $this->pos->tenant(function (): void {
        $bank = Account::query()->where('code', '1110')->firstOrFail();
        $dasar = ['settled_on' => $this->date, 'gross_amount' => '10000', 'bank_account_id' => $bank->id];

        // Tunai tidak pernah jadi piutang, jadi tidak ada yang perlu dicairkan.
        expect(fn () => app(SettlementService::class)->record($dasar + ['method' => 'cash'], $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('SETTLEMENT_METHOD'));

        // Potongan melebihi nilai yang dicairkan.
        expect(fn () => app(SettlementService::class)->record($dasar + ['method' => 'debit', 'fee_amount' => '10001'], $this->owner))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('SETTLEMENT_FEE_TOO_LARGE'));

        // Nomor settlement yang sama dua kali: piutang akan dibersihkan dua kali padahal uangnya sekali.
        app(SettlementService::class)->record($dasar + ['method' => 'debit', 'reference' => 'STL-9'], $this->owner);
        expect(fn () => app(SettlementService::class)->record($dasar + ['method' => 'debit', 'reference' => 'STL-9'], $this->owner))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

it('jurnal pencairan seimbang dan memakai akun yang dipetakan', function () {
    jualKartu($this);
    bukukanHari($this);

    $this->pos->tenant(function (): void {
        app(SettlementService::class)->record([
            'method' => 'debit', 'settled_on' => $this->date, 'gross_amount' => '78500', 'fee_amount' => '1500',
            'bank_account_id' => Account::query()->where('code', '1110')->value('id'),
        ], $this->owner);

        $jurnal = Journal::query()->where('source', 'settlement')->sole();
        $baris = $jurnal->lines()->with('account')->get();

        $debit = $baris->reduce(fn (BigDecimal $c, $l) => $c->plus($l->debit), BigDecimal::zero());
        $credit = $baris->reduce(fn (BigDecimal $c, $l) => $c->plus($l->credit), BigDecimal::zero());
        expect((string) $debit)->toBe((string) $credit);

        $kode = $baris->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]);
        expect($kode['1110']['d'])->toBe('77000.00')   // masuk bank
            ->and($kode['6105']['d'])->toBe('1500.00')  // potongan pencairan
            ->and($kode['1201']['k'])->toBe('78500.00'); // piutang settlement kartu dibersihkan
    });
});
