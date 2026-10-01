<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\Journal;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Saldo awal saat pembukuan dipindahkan ke sistem ini (ACC-09).
 *
 * Ini pekerjaan yang hanya dilakukan sekali per entitas, tetapi kalau salah, **seluruh laporan
 * sesudahnya ikut salah** dan tidak ada satu pun angka yang bisa dipercaya. Karena itu importer ini
 * lebih galak daripada tampak perlu:
 *
 * - Berkasnya dibaca seluruhnya dan divalidasi **sebelum** satu baris pun ditulis. Impor yang
 *   berhenti di tengah meninggalkan buku besar setengah terisi — keadaan yang lebih sulit
 *   diperbaiki daripada tidak mengimpor sama sekali.
 * - Seluruh galat dikumpulkan, bukan berhenti di galat pertama. Orang yang menyiapkan berkas 80
 *   baris tidak boleh diminta mengulang delapan kali untuk menemukan delapan kesalahan.
 * - Tidak seimbang = ditolak. Saldo awal yang timpang berarti ada akun yang terlewat, dan menambal
 *   selisihnya ke akun penyeimbang hanya memindahkan kesalahannya ke tempat yang lebih sulit
 *   ditemukan.
 *
 * Hasilnya satu jurnal bersumber `opening` yang mengikuti maker–checker seperti jurnal lain:
 * terkunci begitu diposting, dan hanya bisa dikoreksi lewat jurnal balik.
 */
class OpeningBalanceImporter
{
    public const SOURCE = 'opening';

    /** Judul kolom yang diterima, supaya berkas dari Excel siapa pun bisa dipakai apa adanya. */
    private const HEADERS = [
        'code' => ['kode', 'kode_akun', 'kode akun', 'account', 'account_code'],
        'debit' => ['debit', 'debet', 'd'],
        'credit' => ['kredit', 'credit', 'k'],
    ];

    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    public static function sourceKey(CarbonImmutable $date): string
    {
        return self::SOURCE.':'.$date->format('Y-m-d');
    }

    public function existing(CarbonImmutable $date): ?Journal
    {
        return Journal::query()->where('source_key', self::sourceKey($date))->first();
    }

    /**
     * Baca berkas dan kembalikan barisnya + daftar masalah. Tidak menulis apa pun.
     *
     * @return array{rows: list<array{code: string, name: string, debit: string, credit: string}>,
     *               problems: list<string>, debit: string, credit: string}
     */
    public function preview(UploadedFile $file): array
    {
        $problems = [];
        $rows = [];
        $debit = BigDecimal::zero();
        $credit = BigDecimal::zero();

        try {
            $raw = $this->read($file);
        } catch (Throwable $e) {
            return ['rows' => [], 'problems' => ['Berkas tidak dapat dibaca: '.$e->getMessage()],
                'debit' => '0.00', 'credit' => '0.00'];
        }

        if ($raw === []) {
            return ['rows' => [], 'problems' => ['Berkas tidak memuat baris data.'], 'debit' => '0.00', 'credit' => '0.00'];
        }

        $accounts = Account::query()->get()->keyBy('code');
        $seen = [];

        foreach ($raw as $index => $row) {
            $baris = $index + 2; // +1 header, +1 karena manusia menghitung dari 1
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                $problems[] = "Baris {$baris}: kode akun kosong.";

                continue;
            }
            /** @var Account|null $account */
            $account = $accounts->get($code);
            if ($account === null) {
                $problems[] = "Baris {$baris}: akun {$code} tidak ada di bagan akun.";

                continue;
            }
            if (! $account->is_postable || ! $account->is_active) {
                $problems[] = "Baris {$baris}: akun {$code} tidak dapat dijurnal (akun induk atau sudah nonaktif).";

                continue;
            }
            if (isset($seen[$code])) {
                $problems[] = "Baris {$baris}: akun {$code} muncul lebih dari sekali.";

                continue;
            }
            /*
             * Ditandai di sini, bukan setelah barisnya lolos: kode yang muncul dua kali tetap
             * masalah walau salah satunya cacat. Menandainya belakangan membuat baris kedua
             * diterima diam-diam, dan saldo awal akun itu jadi separuh dari yang dimaksud.
             */
            $seen[$code] = true;

            $d = $this->parse($row['debit'] ?? null);
            $c = $this->parse($row['credit'] ?? null);
            if ($d === null || $c === null) {
                $problems[] = "Baris {$baris}: nilai debit atau kredit bukan angka.";

                continue;
            }
            if ($d->isPositive() && $c->isPositive()) {
                $problems[] = "Baris {$baris}: akun {$code} diisi debit dan kredit sekaligus.";

                continue;
            }
            if ($d->isZero() && $c->isZero()) {
                // Akun bersaldo nol tidak perlu ikut; dilewati diam-diam, bukan dianggap kesalahan.
                continue;
            }

            $debit = $debit->plus($d);
            $credit = $credit->plus($c);
            $rows[] = ['code' => $code, 'name' => $account->name,
                'debit' => (string) $d->toScale(2), 'credit' => (string) $c->toScale(2)];
        }

        if ($rows !== [] && ! $debit->isEqualTo($credit)) {
            $problems[] = 'Saldo awal tidak seimbang: debit '.$debit->toScale(2).' vs kredit '.$credit->toScale(2)
                .' (selisih '.$debit->minus($credit)->abs()->toScale(2).'). Ada akun yang belum ikut.';
        }

        return ['rows' => $rows, 'problems' => $problems,
            'debit' => (string) $debit->toScale(2), 'credit' => (string) $credit->toScale(2)];
    }

    /**
     * Tulis saldo awal sebagai satu jurnal draft.
     *
     * @param  list<array{code: string, debit: string, credit: string}>  $rows
     */
    public function import(array $rows, CarbonImmutable $date, User $actor): Journal
    {
        if ($this->existing($date) !== null) {
            throw new AccountingException('OPENING_EXISTS',
                'Saldo awal per tanggal ini sudah pernah dibuat. Hapus atau balik jurnalnya dulu bila hendak diganti.',
                409, field: 'date');
        }
        if ($rows === []) {
            throw new AccountingException('OPENING_EMPTY', 'Tidak ada baris yang bisa diimpor.', 422);
        }

        $accounts = Account::query()->whereIn('code', array_column($rows, 'code'))->get()->keyBy('code');
        $lines = [];
        foreach ($rows as $row) {
            $account = $accounts->get($row['code']);
            if ($account === null) {
                throw new AccountingException('OPENING_ACCOUNT', "Akun {$row['code']} tidak ditemukan.", 422);
            }
            $lines[] = ['account_id' => $account->id, 'debit' => $row['debit'], 'credit' => $row['credit'],
                'memo' => 'Saldo awal'];
        }

        $journal = $this->journals->create([
            'journal_date' => $date->format('Y-m-d'),
            'description' => 'Saldo awal per '.$date->format('d M Y'),
            'source' => self::SOURCE,
            'source_key' => self::sourceKey($date),
            'lines' => $lines,
        ], $actor);

        $this->audit->log('opening_balance.imported', $journal, new: [
            'number' => $journal->number, 'rows' => count($lines), 'date' => $date->format('Y-m-d'),
        ], userId: $actor->id);

        return $journal;
    }

    /**
     * @return list<array{code: string, debit: string|null, credit: string|null}>
     */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $reader = in_array($ext, ['xlsx', 'xlsm'], true) ? new XlsxReader : new CsvReader;
        $reader->open($file->getRealPath());

        $map = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => trim((string) $c->getValue()), $row->getCells());
                if ($map === null) {
                    $map = $this->headerMap($cells);

                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $rows[] = [
                    'code' => $cells[$map['code']] ?? '',
                    'debit' => $map['debit'] === null ? null : ($cells[$map['debit']] ?? null),
                    'credit' => $map['credit'] === null ? null : ($cells[$map['credit']] ?? null),
                ];
            }
            break; // hanya sheet pertama
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @return array{code: int, debit: int|null, credit: int|null}
     */
    private function headerMap(array $header): array
    {
        $lower = array_map(fn (string $h) => mb_strtolower($h), $header);
        $find = function (string $key) use ($lower): ?int {
            foreach (self::HEADERS[$key] as $alias) {
                $at = array_search($alias, $lower, true);
                if ($at !== false) {
                    return (int) $at;
                }
            }

            return null;
        };

        $code = $find('code');
        if ($code === null) {
            throw new \RuntimeException('Kolom kode akun tidak ditemukan. Judul kolom harus salah satu dari: '
                .implode(', ', self::HEADERS['code']).'.');
        }

        return ['code' => $code, 'debit' => $find('debit'), 'credit' => $find('credit')];
    }

    /**
     * Terima "1.250.000", "1250000,50", "1250000.50", "1 250 000", dan kosong.
     *
     * Titik itu sendiri ambigu: di berkas Indonesia ia pemisah ribuan, di berkas Inggris ia koma
     * desimal. Aturannya dibuat sejelas mungkin dan bukan tebakan halus:
     *
     * - Ada koma → koma adalah desimal, titik pemisah ribuan (gaya Indonesia).
     * - Tanpa koma, tetapi titiknya berpola ribuan (`25.000.000`) → titik pemisah ribuan.
     * - Tanpa koma, satu titik saja (`1250000.5`) → titik adalah desimal.
     *
     * Nilai negatif ditolak, bukan dibalik arahnya: saldo awal bersaldo minus hampir selalu berarti
     * kolomnya tertukar, dan menebak maksudnya justru menyembunyikan kesalahan itu.
     */
    private function parse(mixed $value): ?BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        $text = str_replace([' ', "\u{00a0}"], '', $text);
        if ($text === '' || $text === '-') {
            return BigDecimal::zero();
        }

        if (str_contains($text, ',')) {
            $normal = str_replace(['.', ','], ['', '.'], $text);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) === 1) {
            $normal = str_replace('.', '', $text);
        } else {
            $normal = $text;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $normal) !== 1) {
            return null;
        }
        $amount = BigDecimal::of($normal);

        return $amount->isNegative() ? null : $amount->toScale(2);
    }
}
