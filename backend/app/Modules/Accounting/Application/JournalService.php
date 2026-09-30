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
            $this->periods->assertOpen($period);

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
            $this->periods->assertOpen($period);

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

    public function post(Journal $journal, User $actor): Journal
    {
        return DB::transaction(function () use ($journal, $actor): Journal {
            /** @var Journal $locked */
            $locked = Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            $this->periods->assertOpen($locked->period()->firstOrFail());

            /*
             * Barisnya diperiksa ULANG dari basis data, bukan dipercaya dari saat draft dibuat.
             * Akun bisa dinonaktifkan di antara kedua saat itu, dan pemeriksaan keseimbangan di sini
             * adalah pemeriksaan terakhir sebelum angkanya masuk buku besar.
             */
            $rows = JournalLine::query()->where('journal_id', $locked->id)->get();
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

            $locked->forceFill([
                'status' => Journal::POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->log('journal.posted', $locked, new: ['number' => $locked->number, 'total' => (string) $debit->toScale(2)], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Jurnal balik: salinan cermin yang langsung diposting, merujuk jurnal asal (ACC-07).
     *
     * Tanggalnya boleh berbeda — koreksi atas jurnal bulan lalu yang periodenya sudah ditutup
     * dicatat di periode berjalan, bukan dipaksakan mundur.
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

            $date = $on ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
            $period = $this->periods->forDate($date);
            $this->periods->assertOpen($period);

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

            $balik = $this->post($balik, $actor);

            $locked->forceFill(['status' => Journal::REVERSED, 'reversed_by_journal_id' => $balik->id])->save();

            $this->audit->log('journal.reversed', $locked, new: ['number' => $locked->number, 'reversal' => $balik->number], reason: $text, userId: $actor->id);

            return $balik;
        });
    }

    public function delete(Journal $journal, User $actor): void
    {
        $this->assertDraft($journal);
        $this->audit->log('journal.deleted', $journal, old: ['number' => $journal->number], userId: $actor->id);
        $journal->delete();
    }

    private function assertDraft(Journal $journal): void
    {
        if (! $journal->isDraft()) {
            throw new AccountingException('JOURNAL_NOT_DRAFT',
                'Jurnal yang sudah diposting tidak dapat diubah atau dihapus. Buat jurnal balik bila perlu dikoreksi.',
                409, field: 'status');
        }
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
            $lines[] = [
                'account_id' => $accountId,
                'debit' => (string) $d->toScale(2),
                'credit' => (string) $c->toScale(2),
                'memo' => isset($row['memo']) && is_string($row['memo']) && trim($row['memo']) !== '' ? mb_substr(trim($row['memo']), 0, 300) : null,
                'brand_id' => $this->dimension($row['brand_id'] ?? null, Brand::class, "lines.{$i}.brand_id"),
                'outlet_id' => $this->dimension($row['outlet_id'] ?? null, Outlet::class, "lines.{$i}.outlet_id"),
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
