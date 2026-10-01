<?php

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Application\JournalAttachments;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Application\PeriodService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\AccountingPeriod;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalAttachment;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Shared\Application\AttachmentStore;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Factory;

/**
 * Pemisahan tugas, lampiran bukti, dan tutup sementara (ACC-05, ACC-04).
 *
 * Yang dijaga di sini bukan "alurnya jalan", melainkan bahwa kontrolnya **tidak bisa dilewati satu
 * orang**: tidak lewat layanan, tidak lewat layar, dan tidak lewat basis data.
 */
beforeEach(function () {
    [$this->company, $this->pengaju] = Factory::company('Kopi Tepi Jalan');
    [$this->pemeriksa] = Factory::staff($this->company, ['finance'], []);
    $this->tanggal = CarbonImmutable::parse('2026-10-05');
    dalamMakerChecker($this, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamMakerChecker(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

function jurnalBaru(object $test, string $nominal = '250000'): Journal
{
    return dalamMakerChecker($test, fn () => app(JournalService::class)->create([
        'journal_date' => $test->tanggal->format('Y-m-d'),
        'description' => 'Setoran kas dari brankas',
        'lines' => [
            ['account_id' => Account::query()->where('code', '1101')->value('id'), 'debit' => $nominal, 'credit' => '0'],
            ['account_id' => Account::query()->where('code', '1110')->value('id'), 'debit' => '0', 'credit' => $nominal],
        ],
    ], $test->pengaju));
}

it('menolak pengaju memposting jurnalnya sendiri', function () {
    dalamMakerChecker($this, function () {
        $service = app(JournalService::class);
        $jurnal = $service->submit(jurnalBaru($this), $this->pengaju);

        expect(fn () => $service->post($jurnal, $this->pengaju))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('SEGREGATION_OF_DUTIES'));

        // Orang lain boleh, dan jejaknya menyimpan keduanya.
        $posted = $service->post($jurnal->refresh(), $this->pemeriksa);
        expect($posted->status)->toBe(Journal::POSTED)
            ->and($posted->submitted_by)->toBe($this->pengaju->id)
            ->and($posted->posted_by)->toBe($this->pemeriksa->id);
    });
});

it('tidak menerima posting jurnal yang belum diajukan', function () {
    dalamMakerChecker($this, function () {
        $draft = jurnalBaru($this);

        expect(fn () => app(JournalService::class)->post($draft, $this->pemeriksa))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_NOT_SUBMITTED'));
    });
});

it('membekukan baris jurnal yang sedang diajukan, sampai ke basis data', function () {
    dalamMakerChecker($this, function () {
        $service = app(JournalService::class);
        $jurnal = $service->submit(jurnalBaru($this), $this->pengaju);

        // Lewat layanan.
        expect(fn () => $service->update($jurnal, ['description' => 'Diubah diam-diam'], $this->pengaju))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('JOURNAL_NOT_DRAFT'));

        /*
         * Dan lewat SQL langsung. Inilah yang membedakan penjagaan sungguhan dari penjagaan di
         * kertas: satu skrip perbaikan data yang ceroboh tidak boleh cukup untuk mengubah angka
         * yang sedang diperiksa orang lain.
         */
        /*
         * Savepointnya dipasang dan dilepas sendiri, bukan lewat DB::transaction. Alasannya bukan
         * gaya: di Postgres satu perintah yang gagal membatalkan SELURUH transaksi berjalan, dan
         * bila galatnya ditangkap di dalam blok transaksi, bloknya "berhasil" sementara koneksinya
         * sudah batal — galat berikutnya lalu bicara tentang RESET ROLE, bukan tentang penjaga yang
         * sedang diuji. Melepas savepoint secara eksplisit membuat koneksinya sehat kembali.
         */
        $ditolak = function (Closure $usaha): bool {
            DB::statement('SAVEPOINT uji_penjaga');
            try {
                $usaha();
                $hasil = false;
            } catch (QueryException) {
                $hasil = true;
            }
            DB::statement('ROLLBACK TO SAVEPOINT uji_penjaga');

            return $hasil;
        };

        expect($ditolak(fn () => DB::table('journal_lines')->where('journal_id', $jurnal->id)->update(['debit' => '999'])))->toBeTrue()
            ->and($ditolak(fn () => DB::table('journal_lines')->where('journal_id', $jurnal->id)->delete()))->toBeTrue()
            ->and(JournalLine::query()->where('journal_id', $jurnal->id)->count())->toBe(2);
    });
});

it('mengembalikan jurnal ke draft berikut alasannya, lalu bisa diajukan ulang', function () {
    dalamMakerChecker($this, function () {
        $service = app(JournalService::class);
        $jurnal = $service->submit(jurnalBaru($this), $this->pengaju);

        expect(fn () => $service->reject($jurnal, $this->pemeriksa, 'ok'))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('REJECT_REASON_REQUIRED'));

        $ditolak = $service->reject($jurnal, $this->pemeriksa, 'Akun lawan salah, seharusnya kas kecil');
        expect($ditolak->status)->toBe(Journal::DRAFT)
            ->and($ditolak->reject_reason)->toBe('Akun lawan salah, seharusnya kas kecil')
            ->and($ditolak->rejected_by)->toBe($this->pemeriksa->id);

        // Setelah kembali draft, barisnya boleh disunting lagi.
        $service->update($ditolak, ['description' => 'Setoran kas dari brankas (diperbaiki)'], $this->pengaju);

        // Diajukan ulang: catatan penolakan lama dibersihkan agar tidak menyesatkan pemeriksa berikutnya.
        $lagi = $service->submit($ditolak->refresh(), $this->pengaju);
        expect($lagi->status)->toBe(Journal::SUBMITTED)->and($lagi->reject_reason)->toBeNull();
    });
});

it('tidak menghitung jurnal yang baru diajukan di buku besar', function () {
    dalamMakerChecker($this, function () {
        app(JournalService::class)->submit(jurnalBaru($this), $this->pengaju);

        // "Diajukan" berarti dinyatakan siap, bukan diterima. Buku besar hanya memuat yang diterima.
        expect(JournalLine::query()->count())->toBe(2);
        $neraca = app(GeneralLedger::class)
            ->trialBalance($this->tanggal->startOfMonth(), $this->tanggal->endOfMonth());
        expect($neraca->rows)->toBeEmpty();
    });
});

it('menolak menutup periode yang masih punya jurnal diajukan', function () {
    dalamMakerChecker($this, function () {
        app(JournalService::class)->submit(jurnalBaru($this), $this->pengaju);
        $periode = AccountingPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();

        expect(fn () => app(PeriodService::class)->close($periode, $this->pemeriksa))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('PERIOD_HAS_DRAFTS'));
    });
});

it('tutup sementara masih menerima koreksi, tutup permanen tidak', function () {
    dalamMakerChecker($this, function () {
        $service = app(JournalService::class);
        $service->post($service->submit(jurnalBaru($this), $this->pengaju), $this->pemeriksa);

        $periode = AccountingPeriod::query()->where('year', 2026)->where('month', 10)->firstOrFail();
        $soft = app(PeriodService::class)->close($periode, $this->pemeriksa, 'Laporan Oktober sudah terbit', hard: false);
        expect($soft->status)->toBe(AccountingPeriod::SOFT_CLOSED)->and($soft->isClosed())->toBeTrue();

        // Koreksi yang memang milik bulan itu masih bisa masuk.
        $koreksi = $service->post($service->submit(jurnalBaru($this, '17500'), $this->pengaju), $this->pemeriksa);
        expect($koreksi->status)->toBe(Journal::POSTED);

        // Setelah tutup permanen, tidak ada lagi yang bisa masuk.
        app(PeriodService::class)->close($periode->refresh(), $this->pemeriksa);
        expect(fn () => jurnalBaru($this, '5000'))
            ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('PERIOD_CLOSED'));
    });
});

describe('lampiran bukti', function () {
    beforeEach(fn () => Storage::fake('attachments'));

    it('melampirkan bukti, termasuk setelah jurnal diposting', function () {
        dalamMakerChecker($this, function () {
            $service = app(JournalService::class);
            $jurnal = $service->post($service->submit(jurnalBaru($this), $this->pengaju), $this->pemeriksa);

            $lampiran = app(JournalAttachments::class)->attach(
                $jurnal, UploadedFile::fake()->image('nota-brankas.jpg'), $this->pengaju);

            expect($lampiran->original_name)->toBe('nota-brankas.jpg')
                ->and(app(AttachmentStore::class)->isValidPath($lampiran->path))->toBeTrue()
                // Jalurnya memuat id entitas: berkas satu entitas tidak pernah satu folder dengan entitas lain.
                ->and(app(AttachmentStore::class)->ownerCompanyId($lampiran->path))->toBe($this->company->id);
            Storage::disk('attachments')->assertExists($lampiran->path);

            // Setelah diposting, bukti tidak boleh dihapus — itu sama saja menghapus jejak.
            expect(fn () => app(JournalAttachments::class)->detach($lampiran, $this->pengaju))
                ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ATTACHMENT_LOCKED'));
        });
    });

    it('menolak jenis berkas yang bukan bukti', function () {
        dalamMakerChecker($this, function () {
            $jurnal = jurnalBaru($this);

            expect(fn () => app(JournalAttachments::class)->attach(
                $jurnal, UploadedFile::fake()->create('pembukuan.xlsx', 12), $this->pengaju))
                ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('ATTACHMENT_TYPE'));

            expect(JournalAttachment::query()->count())->toBe(0);
        });
    });

    it('membuang berkasnya saat lampiran draft dihapus', function () {
        dalamMakerChecker($this, function () {
            $jurnal = jurnalBaru($this);
            $lampiran = app(JournalAttachments::class)->attach(
                $jurnal, UploadedFile::fake()->image('nota.png'), $this->pengaju);
            $path = $lampiran->path;

            app(JournalAttachments::class)->detach($lampiran, $this->pengaju);

            expect(JournalAttachment::query()->count())->toBe(0);
            Storage::disk('attachments')->assertMissing($path);
        });
    });

    it('tidak membocorkan lampiran entitas lain', function () {
        $milikKita = dalamMakerChecker($this, function () {
            $jurnal = jurnalBaru($this);

            return app(JournalAttachments::class)->attach($jurnal, UploadedFile::fake()->image('nota.jpg'), $this->pengaju);
        });

        [$lain] = Factory::company('Warung Bu Ratna');
        Factory::tenant($lain, function () use ($milikKita): void {
            expect(JournalAttachment::query()->count())->toBe(0)
                ->and(JournalAttachment::query()->find($milikKita->id))->toBeNull();
        });
    });
});
