<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jurnal umum (ACC-05, ACC-07).
 *
 * Tiga aturan yang tidak pernah dilonggarkan:
 *
 * 1. **Debit harus sama dengan kredit.** Diperiksa di sini dengan BigDecimal, bukan float —
 *    0.1 + 0.2 di float tidak sama dengan 0.3, dan buku besar yang selisih satu sen adalah buku
 *    besar yang tidak bisa dipercaya.
 * 2. **Hanya akun daun yang aktif boleh dijurnal.** Akun induk dipakai menjumlah di laporan;
 *    menjurnal ke sana membuat jumlah induk tidak lagi sama dengan jumlah anaknya.
 * 3. **Jurnal terposting tidak bisa diubah.** Koreksi lewat jurnal balik yang merujuk aslinya.
 *    Ini juga dijaga trigger basis data, bukan hanya kelas ini.
 * 4. **Yang mengajukan tidak boleh yang memposting** (ACC-05, keputusan user 1 Okt 2026).
 *    Pemisahan tugas ini soal ORANG, bukan izin: dua orang dengan izin yang sama pun tidak boleh
 *    menjadi pengaju sekaligus pemosting jurnal yang sama. Itulah satu-satunya bentuk kontrol
 *    yang tidak bisa dilewati hanya dengan memberi diri sendiri hak akses lebih.
 */
class JournalService
{
    public function __construct(
        private readonly PeriodService $periods,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{journal_date: string, description: string, lines: list<array<string, mixed>>, source?: string, source_key?: string|null}  $data
     */
    public function create(array $data, User $actor): Journal
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $date = $this->date($data['journal_date'] ?? null);
        $lines = $this->validateLines($data['lines'] ?? []);

        return DB::transaction(function () use ($companyId, $date, $lines, $data, $actor): Journal {
            $period = $this->periods->forDate($date);
            $this->periods->assertPostable($period);

            $journal = new Journal;
            $journal->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('JU', $date),
                'journal_date' => $date->format('Y-m-d'),
                'period_id' => $period->id,
                'description' => $this->text($data['description'] ?? '', 'description'),
                'status' => Journal::DRAFT,
                'source' => $data['source'] ?? Journal::SOURCE_MANUAL,
                'source_key' => $data['source_key'] ?? null,
                'created_by' => $actor->id,
            ])->save();

            $this->writeLines($journal, $lines);

            $this->audit->log('journal.created', $journal, new: ['number' => $journal->number, 'total' => (string) $this->total($lines)], userId: $actor->id);

            return $journal->refresh();
        });
    }

    /**
     * @param  array{journal_date?: string, description?: string, lines?: list<array<string, mixed>>}  $data
     */
    public function update(Journal $journal, array $data, User $actor): Journal
    {
        return DB::transaction(function () use ($journal, $data, $actor): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            $date = isset($data['journal_date']) ? $this->date($data['journal_date']) : CarbonImmutable::parse($locked->journal_date);
            $period = $this->periods->forDate($date);
            $this->periods->assertPostable($period);

            $lines = array_key_exists('lines', $data) ? $this->validateLines($data['lines']) : null;

            $locked->forceFill([
                'journal_date' => $date->format('Y-m-d'),
                'period_id' => $period->id,
                'description' => $this->text($data['description'] ?? $locked->description, 'description'),
            ])->save();

            if ($lines !== null) {
                JournalLine::query()->where('journal_id', $locked->id)->delete();
                $this->writeLines($locked, $lines);
            }

            $this->audit->log('journal.updated', $locked, new: ['number' => $locked->number], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Ajukan jurnal untuk diperiksa (ACC-05). Setelah diajukan, barisnya dibekukan — juga di basis data.
     */
    public function submit(Journal $journal, User $actor): Journal
    {
        return DB::transaction(function () use ($journal, $actor): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $this->periods->assertPostable($locked->period()->firstOrFail());
            $this->assertBalancedOnDisk($locked);

            $locked->forceFill([
                'status' => Journal::SUBMITTED,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ])->save();

            $this->audit->log('journal.submitted', $locked, new: ['number' => $locked->number], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /** Kembalikan jurnal yang diajukan ke draft berikut alasannya. */
    public function reject(Journal $journal, User $actor, string $reason): Journal
    {
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new AccountingException('REJECT_REASON_REQUIRED',
                'Alasan penolakan wajib diisi — pengaju perlu tahu apa yang harus diperbaiki.', 422, field: 'reason');
        }

        return DB::transaction(function () use ($journal, $actor, $text): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isSubmitted()) {
                throw new AccountingException('JOURNAL_NOT_SUBMITTED',
                    'Hanya jurnal yang sedang diajukan yang dapat ditolak.', 409, field: 'status');
            }

            $locked->forceFill([
                'status' => Journal::DRAFT,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'reject_reason' => mb_substr($text, 0, 300),
            ])->save();

            $this->audit->log('journal.rejected', $locked, new: ['number' => $locked->number], reason: $text, userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function post(Journal $journal, User $actor): Journal
    {
        return DB::transaction(function () use ($journal, $actor): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $this->assertSubmitted($locked);
            $this->assertDifferentPerson($locked, $actor);
            $this->periods->assertPostable($locked->period()->firstOrFail());

            $debit = $this->assertBalancedOnDisk($locked);

            $locked->forceFill([
                'status' => Journal::POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            /*
             * Jurnal asal baru dinyatakan "dibalik" pada saat pembaliknya benar-benar masuk buku
             * besar — bukan saat pembaliknya dibuat. Selama pembaliknya masih menunggu persetujuan,
             * jurnal asal masih berlaku, dan itulah keadaan yang sebenarnya.
             */
            if ($locked->reverses_journal_id !== null) {
                Journal::query()->whereKey($locked->reverses_journal_id)
                    ->update(['status' => Journal::REVERSED, 'reversed_by_journal_id' => $locked->id, 'updated_at' => now()]);
            }

            $this->audit->log('journal.posted', $locked, new: ['number' => $locked->number, 'total' => (string) $debit->toScale(2)], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Jurnal balik: salinan cermin yang merujuk jurnal asal (ACC-07).
     *
     * Tanggalnya boleh berbeda — koreksi atas jurnal bulan lalu yang periodenya sudah ditutup
     * dicatat di periode berjalan, bukan dipaksakan mundur.
     *
     * Lahir berstatus **diajukan**, bukan langsung diposting: membalik jurnal adalah mengubah
     * angka yang sudah terbit di laporan, jadi justru di sinilah pemeriksaan oleh orang kedua
     * paling dibutuhkan. Jurnal asal baru ditandai "dibalik" saat pembaliknya benar-benar diposting.
     */
    public function reverse(Journal $journal, User $actor, string $reason, ?CarbonImmutable $on = null): Journal
    {
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new AccountingException('REVERSAL_REASON_REQUIRED', 'Alasan pembalikan wajib diisi.', 422, field: 'reason');
        }

        return DB::transaction(function () use ($journal, $actor, $text, $on): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Journal::POSTED) {
                throw new AccountingException('JOURNAL_NOT_POSTED',
                    $locked->status === Journal::REVERSED
                        ? 'Jurnal ini sudah pernah dibalik.'
                        : 'Hanya jurnal yang sudah diposting yang dapat dibalik. Jurnal draft cukup dihapus.',
                    409, field: 'status');
            }

            /*
             * Pembalik yang masih menunggu persetujuan tetap menghalangi pembalik kedua. Tanpa ini,
             * dua orang yang sama-sama merasa perlu membalik jurnal yang sama akan menghasilkan dua
             * pembalik, dan yang kedua justru mengembalikan angkanya seperti semula.
             */
            $menggantung = Journal::query()->where('reverses_journal_id', $locked->id)
                ->whereIn('status', [Journal::DRAFT, Journal::SUBMITTED])->value('number');
            if ($menggantung !== null) {
                throw new AccountingException('REVERSAL_PENDING',
                    "Jurnal balik {$menggantung} untuk jurnal ini sudah dibuat dan masih menunggu persetujuan.",
                    409, field: 'status');
            }

            $date = $on ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
            $period = $this->periods->forDate($date);
            $this->periods->assertPostable($period);

            $balik = new Journal;
            $balik->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $locked->company_id,
                'number' => DocumentNumber::nextForCompany('JB', $date),
                'journal_date' => $date->format('Y-m-d'),
                'period_id' => $period->id,
                'description' => mb_substr('Pembalikan '.$locked->number.' — '.$text, 0, 300),
                'status' => Journal::DRAFT,
                'source' => $locked->source,
                'reverses_journal_id' => $locked->id,
                'created_by' => $actor->id,
            ])->save();

            $no = 1;
            foreach (JournalLine::query()->where('journal_id', $locked->id)->orderBy('line_no')->get() as $line) {
                $this->insertLine($balik, $no++, [
                    'account_id' => $line->account_id,
                    // Cermin: debit jadi kredit dan sebaliknya.
                    'debit' => (string) $line->credit,
                    'credit' => (string) $line->debit,
                    'memo' => $line->memo,
                    'brand_id' => $line->brand_id,
                    'outlet_id' => $line->outlet_id,
                    'counterparty_company_id' => $line->counterparty_company_id,
                ]);
            }

            $balik = $this->submit($balik, $actor);

            $this->audit->log('journal.reversal_requested', $locked,
                new: ['number' => $locked->number, 'reversal' => $balik->number], reason: $text, userId: $actor->id);

            return $balik;
        });
    }

    public function delete(Journal $journal, User $actor): void
    {
        $this->assertDraft($journal);
        $this->audit->log('journal.deleted', $journal, old: ['number' => $journal->number], userId: $actor->id);
        $journal->delete();
    }

    private function brandOfOutlet(?string $outletId): ?string
    {
        if ($outletId === null) {
            return null;
        }

        $brandId = Outlet::query()->whereKey($outletId)->value('brand_id');

        return is_string($brandId) ? $brandId : null;
    }

    private function assertDraft(Journal $journal): void
    {
        if ($journal->isDraft()) {
            return;
        }

        throw new AccountingException('JOURNAL_NOT_DRAFT',
            $journal->isSubmitted()
                ? 'Jurnal ini sedang diajukan dan tidak dapat diubah. Minta pemeriksa menolaknya lebih dulu bila perlu diperbaiki.'
                : 'Jurnal yang sudah diposting tidak dapat diubah atau dihapus. Buat jurnal balik bila perlu dikoreksi.',
            409, field: 'status');
    }

    private function assertSubmitted(Journal $journal): void
    {
        if ($journal->isSubmitted()) {
            return;
        }

        throw new AccountingException('JOURNAL_NOT_SUBMITTED',
            $journal->isDraft()
                ? 'Jurnal ini masih draft. Ajukan dulu, lalu pemeriksa yang mempostingnya.'
                : 'Jurnal ini sudah masuk buku besar.',
            409, field: 'status');
    }

    /**
     * Pemisahan tugas (ACC-05): yang mengajukan tidak boleh yang memposting.
     *
     * Diperiksa terhadap ORANGNYA, bukan izinnya. Verifikator yang memposting jurnal yang ia ajukan
     * sendiri berarti tidak ada seorang pun yang benar-benar memeriksanya — dan kontrol yang bisa
     * dilewati oleh satu orang bukan kontrol.
     */
    private function assertDifferentPerson(Journal $journal, User $actor): void
    {
        if ($journal->submitted_by !== $actor->id) {
            return;
        }

        throw new AccountingException('SEGREGATION_OF_DUTIES',
            'Jurnal ini Anda sendiri yang mengajukan, jadi orang lain yang harus mempostingnya.',
            403, field: 'status', details: ['submitted_by' => $journal->submitted_by]);
    }

    /**
     * Periksa ULANG barisnya dari basis data, bukan dari apa yang tersimpan saat draft dibuat.
     * Akun bisa dinonaktifkan di antara kedua saat itu, dan ini pemeriksaan terakhir sebelum
     * angkanya masuk buku besar.
     *
     * @return BigDecimal jumlah debit (sama dengan kredit bila lolos)
     */
    private function assertBalancedOnDisk(Journal $journal): BigDecimal
    {
        $rows = JournalLine::query()->where('journal_id', $journal->id)->get();
        if ($rows->count() < 2) {
            throw new AccountingException('JOURNAL_TOO_FEW_LINES', 'Jurnal harus punya minimal dua baris.', 422, field: 'lines');
        }
        $debit = $rows->reduce(fn (BigDecimal $c, JournalLine $l) => $c->plus((string) $l->debit), BigDecimal::zero());
        $credit = $rows->reduce(fn (BigDecimal $c, JournalLine $l) => $c->plus((string) $l->credit), BigDecimal::zero());
        if (! $debit->isEqualTo($credit)) {
            throw new AccountingException('JOURNAL_UNBALANCED', 'Debit dan kredit tidak seimbang.', 422, field: 'lines',
                details: ['debit' => (string) $debit->toScale(2), 'credit' => (string) $credit->toScale(2)]);
        }
        $this->assertAccountsPostable($rows->pluck('account_id')->unique()->all());

        return $debit;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validateLines(mixed $input): array
    {
        if (! is_array($input) || count($input) < 2) {
            throw new AccountingException('JOURNAL_TOO_FEW_LINES', 'Jurnal harus punya minimal dua baris.', 422, field: 'lines');
        }

        $lines = [];
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        foreach (array_values($input) as $i => $row) {
            if (! is_array($row)) {
                throw new AccountingException('JOURNAL_LINE_INVALID', 'Baris jurnal tidak valid.', 422, field: "lines.{$i}");
            }
            $d = $this->amount($row['debit'] ?? '0', "lines.{$i}.debit");
            $c = $this->amount($row['credit'] ?? '0', "lines.{$i}.credit");
            if ($d->isPositive() === $c->isPositive()) {
                throw new AccountingException('JOURNAL_LINE_SIDE',
                    'Setiap baris harus diisi debit saja atau kredit saja, dan nilainya lebih dari nol.',
                    422, field: "lines.{$i}");
            }
            $accountId = $row['account_id'] ?? null;
            if (! is_string($accountId) || ! Str::isUuid($accountId)) {
                throw new AccountingException('JOURNAL_LINE_ACCOUNT', 'Akun pada baris jurnal wajib dipilih.', 422, field: "lines.{$i}.account_id");
            }

            $debit = $debit->plus($d);
            $credit = $credit->plus($c);
            $outletId = $this->dimension($row['outlet_id'] ?? null, Outlet::class, "lines.{$i}.outlet_id");
            $lines[] = [
                'account_id' => $accountId,
                'debit' => (string) $d->toScale(2),
                'credit' => (string) $c->toScale(2),
                'memo' => isset($row['memo']) && is_string($row['memo']) && trim($row['memo']) !== '' ? mb_substr(trim($row['memo']), 0, 300) : null,
                'brand_id' => $this->dimension($row['brand_id'] ?? null, Brand::class, "lines.{$i}.brand_id")
                    // Outlet selalu milik satu brand: menanyakannya lagi ke pengisi jurnal hanya
                    // menambah satu isian yang bisa diisi salah dan tidak menambah keterangan apa pun.
                    ?? $this->brandOfOutlet($outletId),
                'outlet_id' => $outletId,
                'counterparty_company_id' => null,
            ];
        }

        if (! $debit->isEqualTo($credit)) {
            throw new AccountingException('JOURNAL_UNBALANCED',
                'Debit dan kredit tidak seimbang. Selisih '.(string) $debit->minus($credit)->abs()->toScale(2).'.',
                422, field: 'lines',
                details: ['debit' => (string) $debit->toScale(2), 'credit' => (string) $credit->toScale(2)]);
        }
        $this->assertAccountsPostable(array_values(array_unique(array_column($lines, 'account_id'))));

        return $lines;
    }

    /** @param  list<string>  $accountIds */
    private function assertAccountsPostable(array $accountIds): void
    {
        $accounts = Account::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        foreach ($accountIds as $id) {
            $account = $accounts->get($id);
            if ($account === null) {
                throw new AccountingException('ACCOUNT_NOT_FOUND', 'Akun tidak ditemukan pada bagan akun entitas ini.', 422, field: 'lines');
            }
            if (! $account->is_postable) {
                throw new AccountingException('ACCOUNT_NOT_POSTABLE',
                    "Akun {$account->label()} adalah akun induk dan tidak dapat dijurnal. Pilih sub-akunnya.",
                    422, field: 'lines');
            }
            if (! $account->is_active) {
                throw new AccountingException('ACCOUNT_INACTIVE', "Akun {$account->label()} sudah tidak aktif.", 422, field: 'lines');
            }
        }
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Journal $journal, array $lines): void
    {
        $no = 1;
        foreach ($lines as $line) {
            $this->insertLine($journal, $no++, $line);
        }
    }

    /** @param  array<string, mixed>  $line */
    private function insertLine(Journal $journal, int $no, array $line): void
    {
        $row = new JournalLine;
        $row->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $journal->company_id,
            'journal_id' => $journal->id,
            'line_no' => $no,
        ] + $line)->save();
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function total(array $lines): BigDecimal
    {
        return array_reduce($lines, fn (BigDecimal $c, array $l) => $c->plus($l['debit']), BigDecimal::zero())->toScale(2);
    }

    private function amount(mixed $value, string $field): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? (string) $value : '0';
        $text = trim($text) === '' ? '0' : trim($text);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            throw new AccountingException('JOURNAL_LINE_AMOUNT', 'Nominal harus angka positif dengan maksimal dua desimal.', 422, field: $field);
        }

        return BigDecimal::of($text);
    }

    /** @param  class-string<Model>  $model */
    private function dimension(mixed $value, string $model, string $field): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        // Dicari lewat model ber-scope tenant: id milik company lain tidak akan ketemu.
        if (! $model::query()->whereKey($value)->exists()) {
            throw new AccountingException('DIMENSION_NOT_FOUND', 'Dimensi yang dipilih tidak ditemukan pada entitas ini.', 422, field: $field);
        }

        return $value;
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AccountingException('JOURNAL_DATE_REQUIRED', 'Tanggal jurnal wajib diisi.', 422, field: 'journal_date');
        }
        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new AccountingException('JOURNAL_DATE_INVALID', 'Tanggal jurnal tidak valid.', 422, field: 'journal_date');
        }
    }

    private function text(mixed $value, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < 3) {
            throw new AccountingException('JOURNAL_DESCRIPTION_REQUIRED', 'Keterangan jurnal wajib diisi minimal 3 huruf.', 422, field: $field);
        }

        return mb_substr($text, 0, 300);
    }
}
