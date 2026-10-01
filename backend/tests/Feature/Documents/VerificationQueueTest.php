<?php

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\PaymentAdviceService;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Application\VerificationQueue;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Tenancy\Application\TenantContext;
use Tests\Support\Factory;

/**
 * Antrian verifikasi pusat (DOC-07).
 *
 * Yang diuji di sini bukan tampilannya, melainkan satu janji: **antrian seseorang hanya memuat yang
 * benar-benar menunggu dia**. Antrian yang memuat dokumen yang tidak bisa ia proses akan diabaikan
 * dalam sepekan, dan sesudah itu tidak ada gunanya lagi memilikinya.
 */
beforeEach(function () {
    [$this->company, $this->owner] = Factory::company('Warung Antrian');
    $this->outlet = Factory::outlet($this->company, null, ['code' => 'ANT']);
    [$this->manajer] = Factory::staff($this->company, ['outlet_manager'], [$this->outlet->id]);
    [$this->finance] = Factory::staff($this->company, ['finance'], []);
    [$this->finance2] = Factory::staff($this->company, ['finance'], []);
    [$this->admin] = Factory::staff($this->company, ['company_admin'], []);

    Factory::tenant($this->company, function (): void {
        app(ChartOfAccounts::class)->installTemplate();
        app(ApprovalMatrix::class)->installDefaults();
    });
});

afterEach(fn () => app(TenantContext::class)->reset());

function antrianSppk(object $test, string $nilai): PaymentRequest
{
    return app(PaymentRequestService::class)->create([
        'request_date' => now()->format('Y-m-d'),
        'payee_name' => 'CV Antrian Panjang',
        'amount' => $nilai,
        'expense_account_id' => Account::query()->where('code', '6108')->value('id'),
        'description' => 'Belanja perlengkapan',
        'outlet_id' => $test->outlet->id,
    ], $test->manajer);
}

it('memuat hanya dokumen yang menunggu peran orang itu', function () {
    Factory::tenant($this->company, function (): void {
        $service = app(PaymentRequestService::class);
        $queue = app(VerificationQueue::class);

        // Rp3 juta: satu tanda tangan, dari finance.
        $kecil = $service->submit(antrianSppk($this, '3000000'), $this->manajer);
        // Rp20 juta: finance lalu company_admin.
        $besar = $service->submit(antrianSppk($this, '20000000'), $this->manajer);

        // Finance melihat keduanya — ia penandatangan tingkat 1 pada dua-duanya.
        $milikFinance = collect($queue->awaitingMySignature($this->finance))->pluck('record.id');
        expect($milikFinance)->toHaveCount(2);

        /*
         * Admin entitas BELUM melihat apa pun: ia penandatangan tingkat 2, dan tingkat 2 tidak boleh
         * menandatangani sebelum tingkat 1. Inilah yang membedakan antrian ini dari sekadar daftar
         * "status = menunggu persetujuan".
         */
        expect($queue->awaitingMySignature($this->admin))->toBe([]);

        // Setelah finance menandatangani yang besar, barulah ia pindah ke antrian admin.
        $service->approve($besar, $this->finance);
        expect(collect($queue->awaitingMySignature($this->admin))->pluck('record.id')->all())
            ->toBe([$besar->id]);
        // Dan hilang dari antrian finance — ia sudah menandatanganinya.
        expect(collect($queue->awaitingMySignature($this->finance))->pluck('record.id')->all())
            ->toBe([$kecil->id]);

        // Pengaju tidak pernah melihat pengajuannya sendiri, walau perannya kebetulan cocok.
        [$financeSekaligusPengaju] = Factory::staff($this->company, ['finance'], []);
        $sendiri = app(PaymentRequestService::class)->create([
            'request_date' => now()->format('Y-m-d'), 'payee_name' => 'Toko Sendiri',
            'amount' => '1000000', 'expense_account_id' => Account::query()->where('code', '6108')->value('id'),
            'description' => 'Belanja sendiri',
        ], $financeSekaligusPengaju);
        $service->submit($sendiri, $financeSekaligusPengaju);
        expect(collect($queue->awaitingMySignature($financeSekaligusPengaju))->pluck('record.id'))
            ->not->toContain($sendiri->id);
    });
});

it('menandai dokumen yang tertahan melebihi tenggat', function () {
    Factory::tenant($this->company, function (): void {
        $queue = app(VerificationQueue::class);
        $sla = $queue->slaDays();

        $baru = app(PaymentRequestService::class)->submit(antrianSppk($this, '3000000'), $this->manajer);
        $lama = app(PaymentRequestService::class)->submit(antrianSppk($this, '4000000'), $this->manajer);

        // Diajukan jauh sebelum tenggat. Waktu diajukan diubah langsung, bukan lewat layanan:
        // yang diuji adalah perhitungan umurnya, bukan cara mengubah tanggal.
        $lama->forceFill(['submitted_at' => now()->subDays($sla + 2)])->save();

        $tertahan = collect($queue->stalled());
        expect($tertahan->pluck('record.id')->all())->toBe([$lama->id])
            ->and($tertahan->first()['age'])->toBe($sla + 2);

        // Yang baru tetap muncul di antrian tanda tangan, hanya tidak bertanda terlambat.
        $milik = collect($queue->awaitingMySignature($this->finance))->keyBy('record.id');
        expect($milik->get($baru->id)['late'])->toBeFalse()
            ->and($milik->get($lama->id)['late'])->toBeTrue();
    });
});

it('tidak menawarkan jurnal kepada orang yang mengajukannya', function () {
    Factory::tenant($this->company, function (): void {
        $queue = app(VerificationQueue::class);
        $journals = app(JournalService::class);

        $jurnal = $journals->create([
            'journal_date' => now()->format('Y-m-d'),
            'description' => 'Penyesuaian kas kecil',
            'lines' => [
                ['account_id' => Account::query()->where('code', '6108')->value('id'), 'debit' => '50000', 'credit' => '0'],
                ['account_id' => Account::query()->where('code', '1110')->value('id'), 'debit' => '0', 'credit' => '50000'],
            ],
        ], $this->finance);
        $journals->submit($jurnal, $this->finance);

        // Pengajunya tidak melihatnya: pemisahan tugas melarangnya memposting, jadi menampilkannya
        // hanya menawarkan tombol yang pasti gagal.
        expect(collect($queue->journalsAwaitingPosting($this->finance))->pluck('record.id'))
            ->not->toContain($jurnal->id);
        expect(collect($queue->journalsAwaitingPosting($this->finance2))->pluck('record.id')->all())
            ->toContain($jurnal->id);
    });
});

it('menyebut advis bayar yang jurnalnya belum terposting', function () {
    Factory::tenant($this->company, function (): void {
        $service = app(PaymentRequestService::class);
        $queue = app(VerificationQueue::class);

        $sppk = $service->approve($service->submit(antrianSppk($this, '3000000'), $this->manajer), $this->finance);
        $advis = app(PaymentAdviceService::class)->issue($sppk, [
            'paid_on' => now()->format('Y-m-d'), 'amount' => '3000000',
            'bank_account_id' => Account::query()->where('code', '1110')->value('id'),
        ], $this->finance);

        expect(collect($queue->advicesNotBooked())->pluck('record.id')->all())->toBe([$advis->id]);

        // Begitu jurnalnya diposting, ia keluar dari antrian — uang keluar dan pembukuannya sejalan.
        $journals = app(JournalService::class);
        $jurnal = $journals->submit($advis->journal()->firstOrFail(), $this->finance);
        $journals->post($jurnal->refresh(), $this->finance2);

        expect($queue->advicesNotBooked())->toBe([]);
    });
});

it('mengurutkan pengajuan yang disetujui tetapi belum dibayar menurut jatuh tempo', function () {
    Factory::tenant($this->company, function (): void {
        $service = app(PaymentRequestService::class);
        $queue = app(VerificationQueue::class);
        $akun = Account::query()->where('code', '6108')->value('id');

        $buat = fn (string $nilai, ?string $tempo) => $service->create([
            'request_date' => now()->format('Y-m-d'), 'payee_name' => 'CV Tempo',
            'amount' => $nilai, 'expense_account_id' => $akun, 'description' => 'Belanja bertempo',
            'due_date' => $tempo,
        ], $this->manajer);

        $tanpaTempo = $service->approve($service->submit($buat('1000000', null), $this->manajer), $this->finance);
        $lewat = $service->approve($service->submit($buat('2000000', now()->subDays(2)->format('Y-m-d')), $this->manajer), $this->finance);
        $nanti = $service->approve($service->submit($buat('3000000', now()->addDays(10)->format('Y-m-d')), $this->manajer), $this->finance);

        $daftar = collect($queue->approvedUnpaid());

        // Jatuh tempo terdekat lebih dulu; yang tanpa tempo di belakang — bukan di depan hanya karena
        // kolomnya kosong.
        expect($daftar->pluck('record.id')->all())->toBe([$lewat->id, $nanti->id, $tanpaTempo->id]);

        $per = $daftar->keyBy('record.id');
        // Terlambat ditentukan jatuh temponya, bukan lamanya disetujui.
        expect($per->get($lewat->id)['late'])->toBeTrue()
            ->and($per->get($nanti->id)['late'])->toBeFalse()
            ->and($per->get($tanpaTempo->id)['late'])->toBeFalse();
    });
});
