<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Treasury\Domain\Models\BankStatement;
use App\Modules\Treasury\Domain\Models\BankStatementLine;
use App\Modules\Treasury\Domain\Models\CashAccount;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Rekonsiliasi bank (CSH-04).
 *
 * Pertanyaan yang dijawab layar ini ada dua, dan keduanya perlu:
 *
 * - **Apa yang ada di bank tetapi tidak ada di buku?** Biaya administrasi, bunga, pendebetan
 *   otomatis — dan, pada kasus terburuk, pengeluaran yang tidak pernah diajukan siapa pun.
 * - **Apa yang ada di buku tetapi tidak ada di bank?** Cek yang belum dicairkan, transfer yang gagal,
 *   dan pembayaran yang dicatat dua kali.
 *
 * Pencocokan otomatis hanya berani pada hal yang pasti: **nilai sama persis, tanggal berdekatan, dan
 * hanya bila pasangannya tunggal**. Begitu ada dua kandidat, sistem tidak memilih — ia menyerahkan
 * keputusannya ke orang, karena menebak pasangan yang salah menghasilkan rekonsiliasi yang terlihat
 * selesai padahal menyembunyikan dua kesalahan sekaligus.
 */
class BankReconciliationService
{
    /** Selisih hari yang masih dianggap transaksi yang sama. Tanggal buku dan tanggal valuta bank kerap berbeda. */
    public const DATE_WINDOW_DAYS = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Impor satu periode rekening koran dari CSV/XLSX.
     *
     * Kolom yang dikenali (tidak peka huruf besar-kecil): tanggal, keterangan, referensi, debet,
     * kredit. Berkas apa adanya dari bank jarang rapi, jadi kesalahan tiap baris dikumpulkan dan
     * dilaporkan sekaligus — bukan berhenti di baris pertama yang bermasalah, yang memaksa orang
     * mengulang impor belasan kali.
     *
     * @return array{statement: BankStatement, imported: int, problems: list<string>}
     */
    public function import(CashAccount $cashAccount, string $path, string $sourceName, User $actor): array
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $rows = $this->readRows($path);
        if ($rows === []) {
            throw new TreasuryException('STATEMENT_EMPTY',
                'Berkas rekening koran tidak memuat baris apa pun.', 422, field: 'file');
        }

        $header = $this->mapHeader(array_shift($rows));
        $problems = [];
        $parsed = [];
        $no = 1;

        foreach ($rows as $i => $row) {
            $baris = $i + 2;
            $tanggal = $this->date($row[$header['tanggal']] ?? null);
            if ($tanggal === null) {
                $problems[] = "Baris {$baris}: tanggal tidak terbaca.";

                continue;
            }
            $debit = $this->money($row[$header['debet']] ?? null);
            $credit = $this->money($row[$header['kredit']] ?? null);
            if ($debit->isZero() && $credit->isZero()) {
                $problems[] = "Baris {$baris}: debet dan kredit dua-duanya kosong.";

                continue;
            }
            if ($debit->isPositive() && $credit->isPositive()) {
                $problems[] = "Baris {$baris}: debet dan kredit terisi dua-duanya — tidak jelas arah uangnya.";

                continue;
            }

            $parsed[] = [
                'line_no' => $no++,
                'value_date' => $tanggal->format('Y-m-d'),
                'description' => mb_substr(trim((string) ($row[$header['keterangan']] ?? '')), 0, 300) ?: 'Tanpa keterangan',
                'reference' => $this->optional($row[$header['referensi']] ?? null, 100),
                'debit' => (string) $debit->toScale(2),
                'credit' => (string) $credit->toScale(2),
            ];
        }

        if ($parsed === []) {
            throw new TreasuryException('STATEMENT_UNREADABLE',
                'Tidak ada satu baris pun yang dapat dibaca. '.implode(' ', array_slice($problems, 0, 3)),
                422, field: 'file', details: ['problems' => $problems]);
        }

        $statement = DB::transaction(function () use ($companyId, $cashAccount, $parsed, $sourceName, $actor): BankStatement {
            $tanggal = array_column($parsed, 'value_date');
            sort($tanggal);

            $statement = new BankStatement;
            $statement->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'cash_account_id' => $cashAccount->id,
                'period_start' => $tanggal[0],
                'period_end' => $tanggal[count($tanggal) - 1],
                'opening_balance' => '0.00',
                'closing_balance' => '0.00',
                'source_name' => mb_substr($sourceName, 0, 150),
                'created_by' => $actor->id,
            ])->save();

            foreach ($parsed as $row) {
                $line = new BankStatementLine;
                $line->forceFill($row + [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'bank_statement_id' => $statement->id,
                ])->save();
            }

            return $statement;
        });

        $this->audit->log('bank_statement.imported', $statement, new: [
            'rekening' => $cashAccount->code, 'baris' => count($parsed), 'masalah' => count($problems),
        ], userId: $actor->id);

        return ['statement' => $statement->refresh(), 'imported' => count($parsed), 'problems' => $problems];
    }

    /**
     * Cocokkan otomatis apa yang pasti.
     *
     * @return int jumlah baris yang berhasil dicocokkan
     */
    public function autoMatch(BankStatement $statement, User $actor): int
    {
        $this->assertUnlocked($statement);
        $cocok = 0;

        foreach ($statement->lines()->whereNull('matched_journal_line_id')->where('is_ignored', false)->get() as $line) {
            $kandidat = $this->candidatesFor($statement, $line);
            // Satu kandidat saja yang boleh dicocokkan sendiri. Dua kandidat berarti sistem harus
            // menebak, dan tebakan yang salah menghasilkan dua kesalahan yang saling menutupi.
            if ($kandidat->count() !== 1) {
                continue;
            }
            $this->match($statement, $line, (string) $kandidat->first()->id, $actor, BankStatementLine::AUTO);
            $cocok++;
        }

        if ($cocok > 0) {
            $this->audit->log('bank_statement.auto_matched', $statement,
                new: ['cocok' => $cocok], userId: $actor->id);
        }

        return $cocok;
    }

    /**
     * Baris jurnal yang mungkin berpasangan dengan satu baris rekening koran.
     *
     * @return Collection<int, JournalLine>
     */
    public function candidatesFor(BankStatement $statement, BankStatementLine $line): Collection
    {
        $nilai = $line->bookSignedAmount();
        $dari = $line->value_date->subDays(self::DATE_WINDOW_DAYS)->format('Y-m-d');
        $sampai = $line->value_date->addDays(self::DATE_WINDOW_DAYS)->format('Y-m-d');

        /** @var Collection<int, JournalLine> $rows */
        $rows = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $statement->cashAccount->account_id)
            ->whereIn('journals.status', Journal::IN_LEDGER)
            ->whereBetween('journals.journal_date', [$dari, $sampai])
            // Arah dan nilainya harus sama persis dari sudut pandang buku kita.
            ->when($nilai->isPositive(),
                fn ($q) => $q->where('journal_lines.debit', (string) $nilai->abs()->toScale(2)),
                fn ($q) => $q->where('journal_lines.credit', (string) $nilai->abs()->toScale(2)))
            ->whereNotIn('journal_lines.id', BankStatementLine::query()
                ->whereNotNull('matched_journal_line_id')
                ->select('matched_journal_line_id'))
            ->with('journal:id,number,journal_date,description')
            ->select('journal_lines.*')
            ->orderBy('journals.journal_date')
            ->get();

        return $rows;
    }

    public function match(BankStatement $statement, BankStatementLine $line, string $journalLineId, User $actor, string $mode = BankStatementLine::MANUAL): BankStatementLine
    {
        $this->assertUnlocked($statement);

        /** @var JournalLine|null $jl */
        $jl = JournalLine::query()->find($journalLineId);
        if ($jl === null || $jl->account_id !== $statement->cashAccount->account_id) {
            throw new TreasuryException('JOURNAL_LINE_MISMATCH',
                'Baris jurnal itu bukan milik akun rekening ini.', 422, field: 'journal_line_id');
        }

        $line->forceFill([
            'matched_journal_line_id' => $jl->id,
            'matched_at' => now(),
            'matched_by' => $actor->id,
            'match_mode' => $mode,
            'is_ignored' => false,
            'ignore_reason' => null,
        ])->save();

        return $line->refresh();
    }

    public function unmatch(BankStatement $statement, BankStatementLine $line, User $actor): BankStatementLine
    {
        $this->assertUnlocked($statement);
        $line->forceFill([
            'matched_journal_line_id' => null, 'matched_at' => null,
            'matched_by' => null, 'match_mode' => null,
        ])->save();
        $this->audit->log('bank_statement_line.unmatched', $line, new: ['baris' => $line->line_no], userId: $actor->id);

        return $line->refresh();
    }

    /**
     * Tandai satu baris sebagai tidak perlu dicocokkan — dengan alasannya.
     *
     * Alasan diwajibkan: baris yang diabaikan tanpa keterangan adalah cara paling halus menutup
     * selisih tanpa menjelaskannya, dan rekonsiliasi yang bisa ditutup begitu kehilangan gunanya.
     */
    public function ignore(BankStatement $statement, BankStatementLine $line, string $reason, User $actor): BankStatementLine
    {
        $this->assertUnlocked($statement);
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new TreasuryException('IGNORE_REASON_REQUIRED',
                'Alasan wajib diisi — baris yang diabaikan tanpa keterangan menutup selisih tanpa menjelaskannya.',
                422, field: 'reason');
        }

        $line->forceFill([
            'is_ignored' => true,
            'ignore_reason' => mb_substr($text, 0, 300),
            'matched_journal_line_id' => null, 'matched_at' => null, 'matched_by' => null, 'match_mode' => null,
        ])->save();
        $this->audit->log('bank_statement_line.ignored', $line,
            new: ['baris' => $line->line_no], reason: $text, userId: $actor->id);

        return $line->refresh();
    }

    /** Kunci hasil rekonsiliasi. Setelah dikunci, barisnya tidak bisa diubah lagi. */
    public function lock(BankStatement $statement, User $actor): BankStatement
    {
        $this->assertUnlocked($statement);
        $sisa = $statement->lines()->whereNull('matched_journal_line_id')->where('is_ignored', false)->count();
        if ($sisa > 0) {
            throw new TreasuryException('STATEMENT_NOT_RECONCILED',
                "Masih ada {$sisa} baris yang belum dicocokkan atau dinyatakan tidak perlu dicocokkan. "
                .'Rekonsiliasi yang dikunci dengan baris menggantung tidak menyatakan apa pun.',
                409, field: 'status');
        }

        $statement->forceFill(['locked_at' => now(), 'locked_by' => $actor->id])->save();
        $this->audit->log('bank_statement.locked', $statement, new: [
            'rekening' => $statement->cashAccount->code,
            'periode' => $statement->period_start->format('Y-m-d').' s/d '.$statement->period_end->format('Y-m-d'),
        ], userId: $actor->id);

        return $statement->refresh();
    }

    /**
     * Ringkasan rekonsiliasi: saldo buku, saldo rekening koran, dan apa saja yang menjelaskan selisihnya.
     */
    public function summary(BankStatement $statement): ReportTable
    {
        $akunId = $statement->cashAccount->account_id;
        $per = $statement->period_end;

        $buku = $this->bookBalance($akunId, $per);

        // Baris buku pada akun ini yang tidak punya pasangan di rekening koran periode ini.
        $belumDiBank = $this->unmatchedBookLines($statement);
        $jumlahBelumDiBank = BigDecimal::zero();
        foreach ($belumDiBank as $jl) {
            $jumlahBelumDiBank = $jumlahBelumDiBank->plus(BigDecimal::of($jl->debit)->minus($jl->credit));
        }

        // Baris rekening koran yang tidak punya pasangan di buku.
        $belumDiBuku = $statement->lines()->whereNull('matched_journal_line_id')->get();
        $jumlahBelumDiBuku = BigDecimal::zero();
        foreach ($belumDiBuku as $line) {
            $jumlahBelumDiBuku = $jumlahBelumDiBuku->plus($line->bookSignedAmount());
        }

        /*
         * Saldo bank yang seharusnya = saldo buku − mutasi buku yang belum muncul di bank
         *                              + mutasi bank yang belum masuk buku.
         * Bila hasilnya sama dengan saldo rekening koran, rekonsiliasinya menjelaskan seluruh selisih.
         */
        $seharusnya = $buku->minus($jumlahBelumDiBank)->plus($jumlahBelumDiBuku);
        $rekening = BigDecimal::of($statement->closing_balance);
        $selisih = $rekening->minus($seharusnya);

        $rows = [
            ['name' => 'Saldo buku per '.$per->translatedFormat('d M Y'), 'amount' => (string) $buku->toScale(2), '_style' => 'item'],
            ['name' => 'Dikurangi: mutasi buku yang belum muncul di rekening ('.$belumDiBank->count().' baris)',
                'amount' => (string) $jumlahBelumDiBank->negated()->toScale(2), '_style' => 'item'],
            ['name' => 'Ditambah: mutasi rekening yang belum masuk buku ('.$belumDiBuku->count().' baris)',
                'amount' => (string) $jumlahBelumDiBuku->toScale(2), '_style' => 'item'],
            ['name' => 'Saldo rekening yang seharusnya', 'amount' => (string) $seharusnya->toScale(2), '_style' => 'subtotal'],
            ['name' => 'Saldo rekening koran', 'amount' => (string) $rekening->toScale(2), '_style' => 'item'],
            ['name' => 'SELISIH YANG BELUM DIJELASKAN', 'amount' => (string) $selisih->toScale(2), '_style' => 'result'],
        ];

        $catatan = $selisih->isZero()
            ? ['Seluruh selisih antara buku dan rekening koran sudah dijelaskan.']
            : ['Selisih belum nol. Periksa saldo akhir rekening koran yang diisikan, dan baris yang belum dicocokkan.'];

        return new ReportTable(
            key: 'rekonsiliasi-bank',
            title: 'Rekonsiliasi Bank',
            subtitle: $statement->cashAccount->label().' · '
                .$statement->period_start->translatedFormat('d M Y').' – '.$per->translatedFormat('d M Y'),
            columns: [
                'name' => ['label' => 'Keterangan', 'type' => ReportTable::TEXT],
                'amount' => ['label' => 'Nilai', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            notes: $catatan,
        );
    }

    /**
     * Baris jurnal pada rekening ini sampai akhir periode yang belum dicocokkan ke baris rekening koran.
     *
     * @return Collection<int, JournalLine>
     */
    public function unmatchedBookLines(BankStatement $statement): Collection
    {
        /** @var Collection<int, JournalLine> $rows */
        $rows = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $statement->cashAccount->account_id)
            ->whereIn('journals.status', Journal::IN_LEDGER)
            ->where('journals.journal_date', '<=', $statement->period_end->format('Y-m-d'))
            ->whereNotIn('journal_lines.id', BankStatementLine::query()
                ->whereNotNull('matched_journal_line_id')
                ->select('matched_journal_line_id'))
            ->with('journal:id,number,journal_date,description')
            ->select('journal_lines.*')
            ->orderBy('journals.journal_date')
            ->get();

        return $rows;
    }

    public function bookBalance(string $accountId, CarbonImmutable $asOf): BigDecimal
    {
        $row = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereIn('journals.status', Journal::IN_LEDGER)
            ->where('journals.journal_date', '<=', $asOf->format('Y-m-d'))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) AS d, COALESCE(SUM(journal_lines.credit),0) AS c')
            ->first();

        return BigDecimal::of((string) ($row->d ?? '0'))->minus((string) ($row->c ?? '0'));
    }

    private function assertUnlocked(BankStatement $statement): void
    {
        if ($statement->isLocked()) {
            throw new TreasuryException('STATEMENT_LOCKED',
                'Rekonsiliasi ini sudah dikunci dan tidak dapat diubah lagi.', 409, field: 'status');
        }
    }

    /**
     * @return list<list<string>>
     */
    private function readRows(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $reader = $ext === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $value = $cell->getValue();
                    $cells[] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : trim((string) $value);
                }
                if (implode('', $cells) !== '') {
                    $rows[] = $cells;
                }
            }
            break; // Hanya lembar pertama: rekening koran tidak pernah tersebar di banyak lembar.
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $alias = [
            'tanggal' => ['tanggal', 'date', 'tgl', 'tanggal transaksi', 'value date', 'posting date'],
            'keterangan' => ['keterangan', 'description', 'uraian', 'berita', 'remark', 'remarks'],
            'referensi' => ['referensi', 'reference', 'ref', 'no. referensi', 'no referensi'],
            'debet' => ['debet', 'debit', 'keluar', 'withdrawal', 'db'],
            'kredit' => ['kredit', 'credit', 'masuk', 'deposit', 'cr'],
        ];

        $peta = [];
        foreach ($header as $i => $judul) {
            $bersih = mb_strtolower(trim($judul));
            foreach ($alias as $kunci => $kemungkinan) {
                if (! isset($peta[$kunci]) && in_array($bersih, $kemungkinan, true)) {
                    $peta[$kunci] = $i;
                }
            }
        }

        foreach (['tanggal', 'debet', 'kredit'] as $wajib) {
            if (! isset($peta[$wajib])) {
                throw new TreasuryException('STATEMENT_HEADER',
                    "Kolom \"{$wajib}\" tidak ditemukan di baris judul. "
                    .'Judul kolom yang dikenali: tanggal, keterangan, referensi, debet, kredit.',
                    422, field: 'file');
            }
        }
        $peta['keterangan'] ??= -1;
        $peta['referensi'] ??= -1;

        return $peta;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return null;
        }
        /*
         * Format diperiksa dengan membentuk ulang teksnya: createFromFormat sendiri terlalu pemaaf
         * (ia menerima "31/02/2026" dan menggesernya ke Maret). Yang menentukan sebuah format benar
         * adalah hasilnya kembali menjadi teks yang sama persis.
         */
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd M Y', 'Y/m/d'] as $format) {
            try {
                // Carbon dalam mode ketat MELEMPAR, bukan mengembalikan null, saat teksnya tidak
                // cocok. Format yang dicoba memang kebanyakan akan gagal — itu cara kerjanya — jadi
                // kegagalannya ditangkap di sini, bukan dibiarkan menghentikan seluruh impor.
                $parsed = CarbonImmutable::createFromFormat($format, $text);
            } catch (\Throwable) {
                continue;
            }
            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $text) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }

    /**
     * Angka dari rekening koran. Pemisah ribuan titik dan koma desimal (gaya Indonesia) maupun
     * sebaliknya sama-sama diterima, karena berkas dari bank yang berbeda memang berbeda.
     */
    private function money(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        $text = str_replace([' ', "\u{a0}", 'Rp'], '', $text);
        if ($text === '' || $text === '-') {
            return BigDecimal::zero();
        }

        if (str_contains($text, ',') && str_contains($text, '.')) {
            // Yang muncul terakhir adalah pemisah desimalnya.
            $text = strrpos($text, ',') > strrpos($text, '.')
                ? str_replace(['.', ','], ['', '.'], $text)
                : str_replace(',', '', $text);
        } elseif (str_contains($text, ',')) {
            $text = str_replace(',', '.', $text);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return BigDecimal::zero();
        }

        return BigDecimal::of($text)->abs();
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
