<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Consolidation\Domain\Models\ConsolidationAdjustment;
use App\Modules\Consolidation\Domain\Models\ConsolidationBalance;
use App\Modules\Consolidation\Domain\Models\ConsolidationEntity;
use App\Modules\Consolidation\Domain\Models\ConsolidationMapping;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Snapshot saldo per entitas dan pengelolaan satu proses konsolidasi (CON-01, CON-02, CON-05 manual).
 *
 * ## Satu pintu lintas tenant, dan apa yang boleh lewat
 *
 * `generate()` adalah satu-satunya tempat yang membaca buku besar entitas lain, dan ia hanya
 * membawa keluar **saldo per akun** — tidak satu baris jurnal, tidak satu nama pemasok, tidak satu
 * nomor dokumen. Itu pembatasan yang disengaja: konsolidasi memang hanya butuh saldo, dan apa pun
 * yang lebih rinci dari itu akan menjadikan layar konsolidasi pintu belakang untuk melihat transaksi
 * entitas lain.
 *
 * Penulisannya pun di luar konteks entitas sumber. Data dikumpulkan di dalam `runAsTenant`, lalu
 * ditulis setelah konteks kembali ke holding — bukan kebetulan: penjaga `BelongsToCompany` memang
 * menolak membuat baris milik company lain, dan menuruti penjaga itu alih-alih mengakalinya adalah
 * yang membuat lapisan isolasi tetap berarti.
 *
 * ## Bisa dijalankan ulang, dan itu syarat
 *
 * Selama `draft`, `generate()` menghapus lalu menulis ulang seluruh snapshot proses itu. Angka anak
 * usaha masih berubah sampai tutup buku, dan modul yang belum ada (penyusutan aset, rekap gaji) akan
 * mengubahnya lagi. Eliminasi manual **tidak** terhapus — yang berubah angka entitasnya, bukan
 * keputusan akuntannya.
 */
class ConsolidationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly GroupService $groups,
        private readonly FinancialStatements $statements,
    ) {}

    /** Buat proses konsolidasi baru untuk satu periode, atau ambil yang sudah ada. */
    public function openRun(Group $group, CarbonImmutable $from, CarbonImmutable $to, ?string $label = null): ConsolidationRun
    {
        if ($to->lessThan($from)) {
            throw new ConsolidationException('PERIOD_INVALID',
                'Tanggal akhir periode tidak boleh sebelum tanggal awal.', field: 'period_end');
        }

        $companyId = app(TenantContext::class)->requireCompanyId();

        return DB::transaction(function () use ($group, $from, $to, $label, $companyId): ConsolidationRun {
            $existing = ConsolidationRun::query()
                ->where('group_id', $group->id)
                ->whereDate('period_start', $from->format('Y-m-d'))
                ->whereDate('period_end', $to->format('Y-m-d'))
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $run = new ConsolidationRun;
            $run->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'group_id' => $group->id,
                'number' => DocumentNumber::nextForCompany(ConsolidationRun::PREFIX, $to),
                'period_start' => $from->format('Y-m-d'),
                'period_end' => $to->format('Y-m-d'),
                'status' => ConsolidationRun::DRAFT,
                'label' => $label !== null && trim($label) !== '' ? mb_substr(trim($label), 0, 120) : null,
            ])->save();

            $this->audit->log('consolidation.opened', $run, new: [
                'number' => $run->number, 'periode' => $run->periodLabel(),
            ]);

            return $run;
        });
    }

    /**
     * Tarik ulang saldo seluruh entitas anggota ke dalam proses ini.
     *
     * @return ConsolidationRun proses yang sudah diperbarui
     */
    public function generate(ConsolidationRun $run): ConsolidationRun
    {
        $this->assertDraft($run);

        /** @var Group $group */
        $group = $run->group ?? throw new ConsolidationException('GROUP_MISSING', 'Grup tidak ditemukan.');
        $members = $this->groups->members($group);
        if ($members === []) {
            throw new ConsolidationException('NO_MEMBERS',
                'Grup ini belum punya entitas anggota. Tambahkan anggota lebih dulu.');
        }

        $from = $run->period_start;
        $to = $run->period_end;

        /*
         * Dikumpulkan lebih dulu, SEMUANYA, sebelum satu baris pun ditulis: menulis sambil berpindah
         * konteks tenant berarti setiap kesalahan meninggalkan snapshot setengah jadi.
         *
         * @var list<array{member: array{id: string, code: string, name: string},
         *                 accounts: array<string, array{name: string, type: string, opening: string, period: string, closing: string}>,
         *                 posted: int, draft: int, last_journal_date: string|null, imbalance: string}> $collected
         */
        $collected = [];
        foreach ($members as $member) {
            /** @var array{accounts: array<string, array{name: string, type: string, opening: string, period: string, closing: string}>, posted: int, draft: int, last_journal_date: string|null, imbalance: string} $data */
            $data = app(TenantContext::class)->runAsTenant(
                $member['id'],
                fn (): array => $this->readEntity($from, $to)
            );
            $collected[] = ['member' => $member] + $data;
        }

        $mappings = $this->mappingIndex($group);

        return DB::transaction(function () use ($run, $collected, $mappings): ConsolidationRun {
            ConsolidationBalance::query()->where('run_id', $run->id)->delete();
            ConsolidationEntity::query()->where('run_id', $run->id)->delete();

            $sequence = 0;
            $balanceRows = [];
            $entityRows = [];
            $now = now();

            foreach ($collected as $entry) {
                $member = $entry['member'];
                $sequence++;

                $entityRows[] = [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $run->company_id,
                    'run_id' => $run->id,
                    'source_company_id' => $member['id'],
                    'source_code' => $member['code'],
                    'source_name' => $member['name'],
                    'sequence' => $sequence,
                    'posted_journal_count' => $entry['posted'],
                    'draft_journal_count' => $entry['draft'],
                    'last_journal_date' => $entry['last_journal_date'],
                    'out_of_balance' => ! BigDecimal::of($entry['imbalance'])->isZero(),
                    'imbalance' => $entry['imbalance'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                foreach ($entry['accounts'] as $code => $akun) {
                    $target = $mappings[$member['id']][$code] ?? $mappings[''][$code] ?? null;
                    $balanceRows[] = [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $run->company_id,
                        'run_id' => $run->id,
                        'source_company_id' => $member['id'],
                        'account_code' => $code,
                        'account_name' => $akun['name'],
                        'account_type' => $akun['type'],
                        'target_code' => $target['code'] ?? $code,
                        'target_name' => $target['name'] ?? $akun['name'],
                        'target_type' => $target['type'] ?? $akun['type'],
                        'opening' => $akun['opening'],
                        'period' => $akun['period'],
                        'closing' => $akun['closing'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($entityRows, 200) as $chunk) {
                DB::table('consolidation_entities')->insert($chunk);
            }
            foreach (array_chunk($balanceRows, 500) as $chunk) {
                DB::table('consolidation_balances')->insert($chunk);
            }

            $run->forceFill([
                'generated_at' => $now,
                'generated_by' => auth()->id(),
                'entity_count' => $sequence,
            ])->save();

            $this->audit->log('consolidation.generated', $run, new: [
                'number' => $run->number,
                'entitas' => $sequence,
                'baris_saldo' => count($balanceRows),
            ]);

            return $run->refresh();
        });
    }

    /**
     * Saldo & keadaan buku satu entitas. Dipanggil DI DALAM konteks entitas itu.
     *
     * @return array{accounts: array<string, array{name: string, type: string, opening: string, period: string, closing: string}>,
     *               posted: int, draft: int, last_journal_date: string|null, imbalance: string}
     */
    private function readEntity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opening = $this->statements->balances(null, $from->subDay());
        $period = $this->statements->balances($from, $to);
        $closing = $this->statements->balances(null, $to);

        /** @var array<string, array{name: string, type: string, opening: string, period: string, closing: string}> $accounts */
        $accounts = [];
        foreach ([$opening, $period, $closing] as $index => $set) {
            $key = ['opening', 'period', 'closing'][$index];
            foreach ($set as $akun) {
                $code = $akun['code'];
                $accounts[$code] ??= [
                    'name' => $akun['name'], 'type' => $akun['type'],
                    'opening' => '0.00', 'period' => '0.00', 'closing' => '0.00',
                ];
                $accounts[$code][$key] = (string) $akun['amount']->toScale(2);
            }
        }
        // Akun yang tiga potongannya nol tidak perlu disimpan: ia hanya menambah baris kosong di
        // kertas kerja, dan kertas kerja yang panjang tidak dibaca siapa pun.
        $accounts = array_filter($accounts, fn (array $a): bool => $a['opening'] !== '0.00'
            || $a['period'] !== '0.00' || $a['closing'] !== '0.00');
        ksort($accounts);

        $counts = DB::table('journals')
            ->selectRaw('status, count(*) as jumlah, max(journal_date) as terakhir')
            ->where('journal_date', '<=', $to->format('Y-m-d'))
            ->groupBy('status')
            ->get();

        $posted = 0;
        $draft = 0;
        $last = null;
        foreach ($counts as $row) {
            $status = (string) $row->status;
            if (in_array($status, Journal::IN_LEDGER, true)) {
                $posted += (int) $row->jumlah;
                $terakhir = $row->terakhir === null ? null : (string) $row->terakhir;
                if ($terakhir !== null && ($last === null || $terakhir > $last)) {
                    $last = $terakhir;
                }
            } else {
                $draft += (int) $row->jumlah;
            }
        }

        /*
         * Ketimpangan buku besar diukur di sini, bukan dibiarkan muncul sebagai neraca konsolidasi
         * yang timpang. Pada titik itu entitas penyebabnya tidak lagi bisa ditunjuk, dan satu-satunya
         * cara mencarinya adalah membuka buku tiap entitas satu per satu.
         */
        $sums = DB::table('journal_lines as jl')
            ->join('journals as j', 'j.id', '=', 'jl.journal_id')
            ->whereIn('j.status', Journal::IN_LEDGER)
            ->where('j.journal_date', '<=', $to->format('Y-m-d'))
            ->selectRaw('coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->first();

        $imbalance = BigDecimal::of((string) ($sums->debit ?? '0'))
            ->minus(BigDecimal::of((string) ($sums->credit ?? '0')));

        return [
            'accounts' => $accounts,
            'posted' => $posted,
            'draft' => $draft,
            'last_journal_date' => $last,
            'imbalance' => (string) $imbalance->toScale(2),
        ];
    }

    /**
     * Pemetaan akun, diindeks: `['' => [kode => target], entitasId => [kode => target]]`.
     *
     * Kunci kosong berarti aturan umum. Aturan khusus entitas dibaca lebih dulu oleh pemanggil,
     * sehingga ia mengalahkan aturan umum tanpa perlu logika urutan.
     *
     * @return array<string, array<string, array{code: string, name: string, type: string}>>
     */
    private function mappingIndex(Group $group): array
    {
        $index = [];
        $rows = ConsolidationMapping::query()->with('account')->where('group_id', $group->id)->get();
        foreach ($rows as $row) {
            $account = $row->account;
            if ($account === null) {
                continue;
            }
            $index[$row->source_company_id ?? ''][$row->source_code] = [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ];
        }

        return $index;
    }

    public function finalize(ConsolidationRun $run): ConsolidationRun
    {
        $this->assertDraft($run);
        if ($run->generated_at === null) {
            throw new ConsolidationException('NOT_GENERATED',
                'Jalankan tarik saldo lebih dulu sebelum menguncinya sebagai final.');
        }

        $run->forceFill([
            'status' => ConsolidationRun::FINAL,
            'finalized_at' => now(),
            'finalized_by' => auth()->id(),
        ])->save();

        $this->audit->log('consolidation.finalized', $run, new: ['number' => $run->number]);

        return $run->refresh();
    }

    /**
     * Buka kembali proses yang sudah final.
     *
     * Alasannya wajib dan tercatat. Angka grup yang sudah dilaporkan ke pemilik lalu berubah tanpa
     * jejak adalah persoalan yang jauh lebih besar daripada angka yang salah.
     */
    public function reopen(ConsolidationRun $run, string $reason): ConsolidationRun
    {
        if ($run->isDraft()) {
            throw new ConsolidationException('ALREADY_DRAFT', 'Proses ini masih draft.');
        }
        $alasan = trim($reason);
        if (mb_strlen($alasan) < 5) {
            throw new ConsolidationException('REASON_REQUIRED',
                'Alasan membuka kembali wajib diisi, minimal 5 karakter.', field: 'reason');
        }

        $run->forceFill([
            'status' => ConsolidationRun::DRAFT,
            'finalized_at' => null,
            'finalized_by' => null,
        ])->save();

        $this->audit->log('consolidation.reopened', $run,
            old: ['status' => ConsolidationRun::FINAL],
            new: ['status' => ConsolidationRun::DRAFT],
            reason: mb_substr($alasan, 0, 500));

        return $run->refresh();
    }

    /**
     * @param  array{kind?: string, debit_account_id: string, credit_account_id: string,
     *               amount: string|float|int, description: string, counterparty_note?: string|null}  $data
     */
    public function addAdjustment(ConsolidationRun $run, array $data): ConsolidationAdjustment
    {
        $this->assertDraft($run);

        $debit = $this->account($data['debit_account_id'] ?? null, 'debit_account_id');
        $credit = $this->account($data['credit_account_id'] ?? null, 'credit_account_id');
        if ($debit->id === $credit->id) {
            throw new ConsolidationException('SAME_ACCOUNT',
                'Akun debit dan kredit tidak boleh sama — mendebit dan mengkredit akun yang sama tidak menghapus apa pun.',
                field: 'credit_account_id');
        }

        $amount = BigDecimal::of((string) ($data['amount'] ?? '0'))->toScale(2);
        if (! $amount->isPositive()) {
            throw new ConsolidationException('AMOUNT_INVALID', 'Nilai harus lebih dari nol.', field: 'amount');
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw new ConsolidationException('FIELD_REQUIRED',
                'Keterangan wajib diisi: eliminasi tanpa keterangan tidak bisa ditelusuri siapa pun.',
                field: 'description');
        }

        $kind = (string) ($data['kind'] ?? ConsolidationAdjustment::ELIMINATION);
        if (! array_key_exists($kind, ConsolidationAdjustment::KIND_LABEL)) {
            throw new ConsolidationException('KIND_INVALID', 'Jenis penyesuaian tidak dikenal.', field: 'kind');
        }

        return DB::transaction(function () use ($run, $debit, $credit, $amount, $description, $kind, $data): ConsolidationAdjustment {
            $next = (int) ConsolidationAdjustment::query()->where('run_id', $run->id)->max('sequence') + 1;

            $row = new ConsolidationAdjustment;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $run->company_id,
                'run_id' => $run->id,
                'sequence' => $next,
                'kind' => $kind,
                'debit_account_id' => $debit->id,
                'credit_account_id' => $credit->id,
                'amount' => (string) $amount,
                'description' => mb_substr($description, 0, 300),
                'counterparty_note' => $this->optional($data['counterparty_note'] ?? null, 200),
                'created_by' => auth()->id(),
            ])->save();

            $this->audit->log('consolidation.adjustment_added', $row, new: [
                'run' => $run->number,
                'debit' => $debit->code,
                'kredit' => $credit->code,
                'nilai' => (string) $amount,
            ]);

            return $row;
        });
    }

    public function deleteAdjustment(ConsolidationAdjustment $row): void
    {
        /** @var ConsolidationRun $run */
        $run = $row->run ?? throw new ConsolidationException('RUN_MISSING', 'Proses konsolidasi tidak ditemukan.');
        $this->assertDraft($run);

        $this->audit->log('consolidation.adjustment_deleted', $row, old: [
            'run' => $run->number, 'nilai' => $row->amount, 'keterangan' => $row->description,
        ]);
        $row->delete();
    }

    /**
     * Catat pemetaan akun lokal → akun konsolidasi (CON-02).
     *
     * @param  array{source_code: string, account_id: string, source_company_id?: string|null, note?: string|null}  $data
     */
    public function map(Group $group, array $data): ConsolidationMapping
    {
        $sourceCode = strtoupper(trim((string) ($data['source_code'] ?? '')));
        if ($sourceCode === '') {
            throw new ConsolidationException('FIELD_REQUIRED', 'Kode akun asal wajib diisi.', field: 'source_code');
        }
        $account = $this->account($data['account_id'] ?? null, 'account_id');
        $sourceCompanyId = $this->optional($data['source_company_id'] ?? null, 36);

        return DB::transaction(function () use ($group, $sourceCode, $account, $sourceCompanyId, $data): ConsolidationMapping {
            $existing = ConsolidationMapping::query()
                ->where('group_id', $group->id)
                ->where('source_code', $sourceCode)
                ->when($sourceCompanyId === null,
                    fn ($query) => $query->whereNull('source_company_id'),
                    fn ($query) => $query->where('source_company_id', $sourceCompanyId))
                ->first();

            $row = $existing ?? (new ConsolidationMapping)->forceFill(['id' => (string) Str::uuid7()]);
            $row->forceFill([
                'company_id' => $group->company_id,
                'group_id' => $group->id,
                'source_company_id' => $sourceCompanyId,
                'source_code' => $sourceCode,
                'account_id' => $account->id,
                'note' => $this->optional($data['note'] ?? null, 200),
            ])->save();

            return $row;
        });
    }

    private function assertDraft(ConsolidationRun $run): void
    {
        if (! $run->isDraft()) {
            throw new ConsolidationException('RUN_FINAL',
                'Proses konsolidasi ini sudah final. Buka kembali lebih dulu bila angkanya memang harus berubah.');
        }
    }

    private function account(mixed $id, string $field): Account
    {
        $account = $id === null ? null : Account::query()->whereKey((string) $id)->first();
        if ($account === null) {
            throw new ConsolidationException('ACCOUNT_NOT_FOUND', 'Akun tidak ditemukan.', field: $field);
        }
        if (! $account->is_postable) {
            throw new ConsolidationException('ACCOUNT_NOT_POSTABLE',
                "Akun {$account->code} adalah akun induk; pilih akun rinciannya.", field: $field);
        }

        return $account;
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
