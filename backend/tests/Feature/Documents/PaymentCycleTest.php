<?php

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Application\PaymentAdviceService;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Tests\Support\Factory;

/**
 * Siklus pengeluaran tanpa kertas (DOC-02, 04, 05, 09, 10).
 *
 * Satu janji menaungi berkas ini: **uang tidak keluar tanpa tanda tangan yang benar**. Bukan tanda
 * tangan siapa pun, bukan tanda tangan orang yang sama dua kali, dan bukan tanda tangan atas angka
 * yang kemudian berubah.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Kopi Tepi Jalan');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'KMG']);
    [$this->manajer] = Factory::staff($this->company, ['outlet_manager'], [$this->outlet->id]);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->admin] = Factory::staff($this->company, ['company_admin'], []);

    dalamDokumen($this, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(ApprovalMatrix::class)->installDefaults();
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamDokumen(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function ajukanPembayaran(object $test, string $nilai, ?object $pemohon = null): PaymentRequest
{
    return dalamDokumen($test, fn () => app(PaymentRequestService::class)->create([
        'request_date' => '2026-10-05',
        'payee_name' => 'CV Sumber Rejeki',
        'amount' => $nilai,
        'expense_account_id' => Account::query()->where('code', '6108')->value('id'),
        'description' => 'Perbaikan mesin kopi',
        'outlet_id' => $test->outlet->id,
    ], $pemohon ?? $test->manajer));
}

describe('matriks batas wewenang (DOC-09)', function () {
    it('memilih band dari batas terkecil yang masih memuat nilainya', function () {
        dalamDokumen($this, function (): void {
            $matrix = app(ApprovalMatrix::class);

            // Rp3 juta masuk band "sampai 5 juta": satu tanda tangan.
            expect($matrix->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of('3000000')))
                ->toBe([['level' => 1, 'role' => 'finance']]);

            // Rp20 juta masuk band "sampai 50 juta": dua tanda tangan.
            expect($matrix->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of('20000000')))
                ->toBe([['level' => 1, 'role' => 'finance'], ['level' => 2, 'role' => 'company_admin']]);

            // Di atas seluruh band berbatas: jatuh ke band tanpa batas, tiga tanda tangan.
            expect($matrix->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of('900000000')))
                ->toHaveCount(3);
        });
    });

    it('mengatakan apa adanya bila matriksnya belum diatur', function () {
        [$lain] = Factory::company('Warung Bu Ratna');
        Factory::tenant($lain, function (): void {
            expect(fn () => app(ApprovalMatrix::class)->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of('1000')))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('APPROVAL_MATRIX_MISSING'));
        });
    });
});

describe('persetujuan berjenjang (DOC-04)', function () {
    it('menolak pemohon menyetujui pengajuannya sendiri', function () {
        $sppk = ajukanPembayaran($this, '3000000', $this->finance);
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $diajukan = $service->submit($sppk, $this->finance);

            // Finance memang peran yang dituntut tingkat 1 — tetapi ia pemohonnya.
            expect(fn () => $service->approve($diajukan, $this->finance))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('SEGREGATION_OF_DUTIES'));

            // Finance lain boleh.
            expect($service->approve($diajukan->refresh(), $this->finance2)->status)->toBe(PaymentRequest::APPROVED);
        });
    });

    it('menuntut peran yang benar di tiap tingkat, dan berurutan', function () {
        $sppk = ajukanPembayaran($this, '20000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $diajukan = $service->submit($sppk, $this->manajer);
            expect($diajukan->required_levels)->toBe(2);

            // Admin tidak boleh menandatangani tingkat 1 — itu jatah finance.
            expect(fn () => $service->approve($diajukan, $this->admin))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('APPROVAL_ROLE_REQUIRED'));

            $satu = $service->approve($diajukan, $this->finance);
            expect($satu->status)->toBe(PaymentRequest::SUBMITTED)->and($satu->nextLevel())->toBe(2);

            // Orang yang sama tidak boleh mengisi tingkat kedua: dua tanda tangan dari satu orang
            // bukan dua pemeriksaan.
            expect(fn () => $service->approve($satu, $this->finance))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('ALREADY_SIGNED'));

            $lengkap = $service->approve($satu->refresh(), $this->admin);
            expect($lengkap->status)->toBe(PaymentRequest::APPROVED)
                ->and($lengkap->approvals()->count())->toBe(2)
                ->and($lengkap->approvals()->pluck('role')->all())->toBe(['finance', 'company_admin']);
        });
    });

    it('membekukan jumlah tanda tangan saat diajukan, walau matriksnya berubah kemudian', function () {
        $sppk = ajukanPembayaran($this, '3000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $diajukan = $service->submit($sppk, $this->manajer);
            expect($diajukan->required_levels)->toBe(1);

            /*
             * Matriks diperketat SETELAH dokumen diajukan. Dokumen yang sedang berjalan tetap
             * memakai aturan saat ia diajukan — kalau tidak, seseorang bisa melonggarkan matriks di
             * tengah jalan dan membuat pengajuan "selesai" dengan tanda tangan yang kurang.
             */
            app(ApprovalMatrix::class)->set(ApprovalRule::PAYMENT_REQUEST, '5000000', 2, 'company_admin');

            expect($service->approve($diajukan, $this->finance)->status)->toBe(PaymentRequest::APPROVED);
        });
    });

    it('menolak mengubah pengajuan yang sedang diperiksa', function () {
        $sppk = ajukanPembayaran($this, '3000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $diajukan = $service->submit($sppk, $this->manajer);

            expect(fn () => $service->update($diajukan, ['amount' => '50000000'], $this->manajer))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('REQUEST_NOT_EDITABLE'));
        });
    });

    it('menolak membuang tanda tangan yang sudah ada, lalu mengulang dari nol', function () {
        $sppk = ajukanPembayaran($this, '20000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $diajukan = $service->submit($sppk, $this->manajer);
            $service->approve($diajukan, $this->finance);

            $ditolak = $service->reject($diajukan->refresh(), $this->admin, 'Nota belum dilampirkan');
            expect($ditolak->status)->toBe(PaymentRequest::DRAFT)
                ->and($ditolak->reject_reason)->toBe('Nota belum dilampirkan')
                /*
                 * Tanda tangan yang sudah terkumpul ikut dibuang. Dokumen yang kembali ke draft
                 * boleh berubah isinya, dan tanda tangan atas isi yang lama tidak berlaku untuk
                 * isi yang baru.
                 */
                ->and($ditolak->approvals()->count())->toBe(0);

            // Setelah diperbaiki dan diajukan ulang, tanda tangan dimulai lagi dari tingkat 1.
            $service->update($ditolak, ['amount' => '19000000'], $this->manajer);
            $lagi = $service->submit($ditolak->refresh(), $this->manajer);
            expect($lagi->nextLevel())->toBe(1)->and($lagi->reject_reason)->toBeNull();
        });
    });
});

describe('advis bayar (DOC-05)', function () {
    it('membayar sebagian dua kali, dan jurnalnya mendebit akun dari SPPK', function () {
        $sppk = ajukanPembayaran($this, '10000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            // 10 juta masuk band 50 juta: dua tanda tangan.
            $satu = $service->approve($service->submit($sppk, $this->manajer), $this->finance);
            $disetujui = $service->approve($satu, $this->admin);
            expect($disetujui->status)->toBe(PaymentRequest::APPROVED);

            $bank = Account::query()->where('code', '1110')->firstOrFail();
            $advis = app(PaymentAdviceService::class);

            $pertama = $advis->issue($disetujui, [
                'paid_on' => '2026-10-06', 'amount' => '6000000',
                'bank_account_id' => $bank->id, 'reference' => 'TRF-001',
            ], $this->finance);

            expect($pertama->number)->toStartWith('AB-2610-')
                ->and($disetujui->refresh()->paid_amount)->toBe('6000000.00')
                ->and($disetujui->status)->toBe(PaymentRequest::APPROVED); // belum lunas

            // Jurnalnya: Dr akun dari SPPK, Cr bank.
            $jurnal = Journal::query()->whereKey($pertama->journal_id)->with('lines.account')->sole();
            $baris = $jurnal->lines->mapWithKeys(fn ($l) => [$l->account->code => ['d' => (string) $l->debit, 'k' => (string) $l->credit]]);
            expect($jurnal->source)->toBe('payment')
                ->and($baris['6108']['d'])->toBe('6000000.00')
                ->and($baris['1110']['k'])->toBe('6000000.00')
                // Dimensi outlet ikut, sehingga laba rugi per outlet memuat beban ini.
                ->and($jurnal->lines->first()->outlet_id)->toBe($this->outlet->id);

            $advis->issue($disetujui->refresh(), [
                'paid_on' => '2026-10-08', 'amount' => '4000000', 'bank_account_id' => $bank->id,
            ], $this->finance);

            expect($disetujui->refresh()->status)->toBe(PaymentRequest::PAID)
                ->and($disetujui->paid_amount)->toBe('10000000.00')
                ->and($disetujui->outstanding()->isZero())->toBeTrue();
        });
    });

    it('menolak membayar melebihi sisa, dan membayar yang belum disetujui', function () {
        $sppk = ajukanPembayaran($this, '5000000');
        dalamDokumen($this, function () use ($sppk): void {
            $bank = Account::query()->where('code', '1110')->firstOrFail();
            $advis = app(PaymentAdviceService::class);
            $dasar = ['paid_on' => '2026-10-06', 'bank_account_id' => $bank->id];

            // Masih draft.
            expect(fn () => $advis->issue($sppk, $dasar + ['amount' => '1000'], $this->finance))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('REQUEST_NOT_APPROVED'));

            $service = app(PaymentRequestService::class);
            $disetujui = $service->approve($service->submit($sppk, $this->manajer), $this->finance);

            expect(fn () => $advis->issue($disetujui, $dasar + ['amount' => '5000001'], $this->finance))
                ->toThrow(fn (DocumentException $e) => expect($e->errorCode)->toBe('ADVICE_EXCEEDS_REQUEST'));
        });
    });

    it('jurnal pembayaran tetap melewati maker–checker seperti jurnal lain', function () {
        $sppk = ajukanPembayaran($this, '2000000');
        dalamDokumen($this, function () use ($sppk): void {
            $service = app(PaymentRequestService::class);
            $disetujui = $service->approve($service->submit($sppk, $this->manajer), $this->finance);
            $advis = app(PaymentAdviceService::class)->issue($disetujui, [
                'paid_on' => '2026-10-06', 'amount' => '2000000',
                'bank_account_id' => Account::query()->where('code', '1110')->value('id'),
            ], $this->finance);

            $jurnal = Journal::query()->whereKey($advis->journal_id)->sole();
            expect($jurnal->status)->toBe(Journal::DRAFT);

            // Yang membuatnya tidak boleh memostingnya sendiri.
            $journals = app(JournalService::class);
            $journals->submit($jurnal, $this->finance);
            expect(fn () => $journals->post($jurnal->refresh(), $this->finance))
                ->toThrow(AccountingException::class);

            expect($journals->post($jurnal->refresh(), $this->finance2)->status)->toBe(Journal::POSTED);
        });
    });
});

it('tidak membocorkan dokumen pembayaran antar entitas', function () {
    ajukanPembayaran($this, '1000000');

    [$lain] = Factory::company('Warung Bu Ratna');
    Factory::tenant($lain, function (): void {
        expect(PaymentRequest::query()->count())->toBe(0)
            ->and(ApprovalRule::query()->count())->toBe(0);
    });
});
