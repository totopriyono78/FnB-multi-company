<?php

use App\Modules\Accounting\Application\AccountingException;
use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Application\OpeningBalanceImporter;
use App\Modules\Accounting\Application\RecurringJournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\RecurringJournal;
use App\Modules\Tenancy\Application\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\Factory;

/**
 * Saldo awal (ACC-09) dan jurnal berulang (ACC-06).
 *
 * Keduanya sekali-jalan yang akibatnya panjang: saldo awal yang salah membuat seluruh laporan
 * sesudahnya salah, dan templat yang terlewat satu bulan membuat laba bulan itu terlihat lebih
 * besar dari yang sebenarnya.
 */
beforeEach(function () {
    [$this->company, $this->pengaju] = Factory::company('Kopi Tepi Jalan');
    [$this->pemeriksa] = Factory::staff($this->company, ['finance'], []);
    dalamAwal($this, fn () => app(ChartOfAccounts::class)->installTemplate());
});

afterEach(fn () => app(TenantContext::class)->reset());

function dalamAwal(object $test, Closure $fn): mixed
{
    return Factory::tenant($test->company, $fn);
}

/** Berkas CSV sementara sebagai unggahan. */
function berkasSaldo(string $isi, string $nama = 'saldo-awal.csv'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.Str::random(8).'-'.$nama;
    file_put_contents($path, $isi);

    return new UploadedFile($path, $nama, 'text/csv', null, true);
}

describe('saldo awal (ACC-09)', function () {
    it('mengimpor saldo awal yang seimbang menjadi jurnal draft', function () {
        dalamAwal($this, function () {
            $csv = "kode,debit,kredit\n1110,25.000.000,0\n1301,5.000.000,0\n2101,0,4.000.000\n3101,0,26.000.000\n";
            $hasil = app(OpeningBalanceImporter::class)->preview(berkasSaldo($csv));

            expect($hasil['problems'])->toBe([])
                ->and($hasil['rows'])->toHaveCount(4)
                // Pemisah ribuan Indonesia dibaca apa adanya — berkas dari Excel lokal tidak perlu dibersihkan dulu.
                ->and($hasil['debit'])->toBe('30000000.00')
                ->and($hasil['credit'])->toBe('30000000.00');

            $tanggal = CarbonImmutable::parse('2026-09-30');
            /** @var list<array{code: string, debit: string, credit: string}> $rows */
            $rows = $hasil['rows'];
            $jurnal = app(OpeningBalanceImporter::class)->import($rows, $tanggal, $this->pengaju);

            expect($jurnal->status)->toBe(Journal::DRAFT)
                ->and($jurnal->source)->toBe('opening')
                ->and($jurnal->source_key)->toBe('opening:2026-09-30')
                ->and($jurnal->lines()->count())->toBe(4);

            // Setelah diposting, angkanya benar-benar menjadi saldo awal di buku besar.
            $service = app(JournalService::class);
            $service->post($service->submit($jurnal, $this->pengaju), $this->pemeriksa);

            $neraca = app(GeneralLedger::class)->trialBalance($tanggal, $tanggal);
            expect($neraca->totals['closing_debit'])->toBe('30000000.00')
                ->and($neraca->totals['closing_credit'])->toBe('30000000.00');
        });
    });

    it('mengumpulkan seluruh masalah sekaligus, bukan berhenti di yang pertama', function () {
        dalamAwal($this, function () {
            $csv = "kode,debit,kredit\n"
                ."9999,1000,0\n"        // akun tidak ada
                ."1000,2000,0\n"        // akun induk
                ."1110,1000,500\n"      // dua sisi sekaligus
                ."1301,abc,0\n"         // bukan angka
                ."1110,1000,0\n"        // ganda
                .",1000,0\n";           // kode kosong

            $hasil = app(OpeningBalanceImporter::class)->preview(berkasSaldo($csv));

            // Enam baris bermasalah, enam keterangan — orang tidak perlu mengulang enam kali.
            expect($hasil['problems'])->toHaveCount(6)
                ->and($hasil['rows'])->toBe([]);
            expect(implode(' ', $hasil['problems']))
                ->toContain('9999')->toContain('akun induk')->toContain('lebih dari sekali');
        });
    });

    it('menolak saldo awal yang tidak seimbang', function () {
        dalamAwal($this, function () {
            $csv = "kode,debit,kredit\n1110,10000,0\n3101,0,9000\n";
            $hasil = app(OpeningBalanceImporter::class)->preview(berkasSaldo($csv));

            expect($hasil['problems'])->toHaveCount(1)
                ->and($hasil['problems'][0])->toContain('tidak seimbang');
        });
    });

    it('menolak mengimpor dua kali untuk tanggal yang sama', function () {
        dalamAwal($this, function () {
            $rows = [['code' => '1110', 'debit' => '1000.00', 'credit' => '0.00'],
                ['code' => '3101', 'debit' => '0.00', 'credit' => '1000.00']];
            $tanggal = CarbonImmutable::parse('2026-09-30');
            app(OpeningBalanceImporter::class)->import($rows, $tanggal, $this->pengaju);

            expect(fn () => app(OpeningBalanceImporter::class)->import($rows, $tanggal, $this->pengaju))
                ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('OPENING_EXISTS'));
        });
    });

    it('menolak berkas tanpa kolom kode akun', function () {
        dalamAwal($this, function () {
            $hasil = app(OpeningBalanceImporter::class)->preview(berkasSaldo("akun,nilai\n1110,1000\n"));

            expect($hasil['problems'][0])->toContain('Kolom kode akun tidak ditemukan');
        });
    });
});

describe('jurnal berulang (ACC-06)', function () {
    beforeEach(function () {
        $this->template = dalamAwal($this, function () {
            $row = new RecurringJournal;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $this->company->id,
                'name' => 'Sewa ruko',
                'description' => 'Beban sewa ruko',
                'day_of_month' => 31,
                'starts_on' => '2026-07-01',
                'ends_on' => null,
                'is_active' => true,
                'lines' => [
                    ['account_id' => Account::query()->where('code', '6102')->value('id'), 'debit' => '7500000', 'credit' => '0'],
                    ['account_id' => Account::query()->where('code', '1110')->value('id'), 'debit' => '0', 'credit' => '7500000'],
                ],
                'created_by' => $this->pengaju->id,
            ])->save();

            return $row;
        });
    });

    it('menyusul bulan-bulan yang terlewat dan tidak pernah membuat dua kali', function () {
        dalamAwal($this, function () {
            $dibuat = app(RecurringJournalService::class)
                ->run(CarbonImmutable::parse('2026-10-05'), $this->pengaju, lookbackMonths: 3);

            // Juli, Agustus, September sudah lewat tanggal 31-nya; Oktober belum (baru tanggal 5).
            expect($dibuat)->toBe(3)
                ->and(Journal::query()->where('source', 'recurring')->count())->toBe(3);

            // Dijalankan lagi: tidak ada yang bertambah.
            expect(app(RecurringJournalService::class)->run(CarbonImmutable::parse('2026-10-05'), $this->pengaju))->toBe(0);
        });
    });

    it('tanggal 31 jatuh ke hari terakhir pada bulan yang lebih pendek', function () {
        dalamAwal($this, function () {
            /*
             * Templat "tiap tanggal 31" tidak boleh melompati Februari. Kalau ia diam, beban bulan
             * itu hilang tanpa ada yang tahu sampai tutup tahun.
             */
            expect($this->template->dateFor(CarbonImmutable::parse('2027-02-01'))->format('Y-m-d'))->toBe('2027-02-28')
                ->and($this->template->dateFor(CarbonImmutable::parse('2026-09-01'))->format('Y-m-d'))->toBe('2026-09-30')
                ->and($this->template->dateFor(CarbonImmutable::parse('2026-10-01'))->format('Y-m-d'))->toBe('2026-10-31');
        });
    });

    it('jurnalnya lahir draft dan tetap melewati pengajuan', function () {
        dalamAwal($this, function () {
            app(RecurringJournalService::class)->run(CarbonImmutable::parse('2026-07-31'), $this->pengaju, lookbackMonths: 0);

            $jurnal = Journal::query()->where('source', 'recurring')->sole();
            expect($jurnal->status)->toBe(Journal::DRAFT)
                ->and($jurnal->description)->toContain('Juli 2026')
                ->and($jurnal->journal_date->format('Y-m-d'))->toBe('2026-07-31');

            // Pembuat templat pun tidak boleh memposting jurnalnya sendiri.
            $service = app(JournalService::class);
            $service->submit($jurnal, $this->pengaju);
            expect(fn () => $service->post($jurnal->refresh(), $this->pengaju))
                ->toThrow(fn (AccountingException $e) => expect($e->errorCode)->toBe('SEGREGATION_OF_DUTIES'));
        });
    });

    it('templat nonaktif dan yang sudah berakhir tidak dibuatkan jurnal', function () {
        dalamAwal($this, function () {
            $this->template->forceFill(['ends_on' => '2026-08-31'])->save();
            expect(app(RecurringJournalService::class)->run(CarbonImmutable::parse('2026-10-05'), $this->pengaju))->toBe(2);

            Journal::query()->where('source', 'recurring')->delete();
            $this->template->forceFill(['is_active' => false])->save();
            expect(app(RecurringJournalService::class)->run(CarbonImmutable::parse('2026-10-05'), $this->pengaju))->toBe(0);
        });
    });
});
