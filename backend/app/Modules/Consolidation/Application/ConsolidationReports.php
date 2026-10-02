<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Accounting\Application\FinancialStatements;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Consolidation\Domain\Models\ConsolidationAdjustment;
use App\Modules\Consolidation\Domain\Models\ConsolidationBalance;
use App\Modules\Consolidation\Domain\Models\ConsolidationEntity;
use App\Modules\Consolidation\Domain\Models\ConsolidationRun;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportTable;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kertas kerja konsolidasi (CON-06) serta Neraca & Laba Rugi konsolidasi (CON-07).
 *
 * ## Konsolidasi manajerial, dan itu dikatakan di catatan kaki
 *
 * Yang dibangun di sini adalah penjumlahan entitas ditambah eliminasi — **tanpa kepemilikan
 * minoritas dan tanpa persentase kepemilikan**. Itu keputusan, bukan kekurangan: konsolidasi
 * statutory menuntut aturan yang hanya boleh ditetapkan akuntan atau auditor klien, dan menebaknya
 * akan menghasilkan laporan yang terlihat resmi tetapi salah — jenis kesalahan yang paling mahal.
 * Setiap laporan di kelas ini mengatakannya sendiri di catatan kaki, supaya tidak ada yang mengirim
 * laporan ini ke bank dengan anggapan ia LK konsolidasi statutory.
 *
 * ## Angka mana yang dipakai
 *
 * Snapshot menyimpan tiga potongan waktu tiap akun. Yang dipakai ditentukan jenis akunnya, dan
 * selalu: akun neraca memakai **saldo akhir**, akun laba rugi memakai **mutasi periode**. Karena itu
 * satu kertas kerja cukup — kolomnya memuat tepat angka yang masuk ke kedua laporan, sehingga
 * memeriksa kertas kerja berarti memeriksa laporannya.
 *
 * ## Eliminasi menyentuh kedua potongan
 *
 * Satu ayat eliminasi berlaku untuk periode yang sedang dikonsolidasi, jadi ia mengubah mutasi
 * periode sekaligus saldo akhir — persis seperti jurnal bertanggal di dalam periode itu. Saldo awal
 * tidak disentuh: eliminasi periode lalu adalah urusan proses konsolidasi periode lalu.
 */
class ConsolidationReports
{
    public function __construct(private readonly FinancialStatements $statements) {}

    /**
     * Kertas kerja: kolom entitas 1..n → Jumlah → Eliminasi → Konsolidasi.
     */
    public function worksheet(ConsolidationRun $run): ReportTable
    {
        $entities = $this->entities($run);
        $accounts = $this->accountIndex($run);
        /*
         * Dua potongan waktu, dan keduanya memang dibutuhkan.
         *
         * Yang ditampilkan adalah potongan yang masuk ke laporan: akun neraca memakai saldo akhir,
         * akun laba rugi memakai mutasi periode. Tetapi baris PEMERIKSAAN tidak bisa memakai itu:
         * aset per tanggal akhir memuat SELURUH laba sejak jurnal pertama, sedangkan mutasi periode
         * hanya laba bulan ini. Memeriksa keduanya satu sama lain akan selalu memberi selisih sebesar
         * laba periode-periode sebelumnya — dan selisih itu akan terbaca sebagai ketimpangan buku
         * yang sebenarnya tidak ada. Jadi pemeriksaannya memakai laba KUMULATIF, dan kertas kerja
         * menampilkan kedua angka laba itu secara terpisah alih-alih memilih satu dan menyesatkan.
         */
        $perEntity = $this->perEntityFigures($run, hybrid: true);
        $perEntityClosing = $this->perEntityFigures($run, hybrid: false);
        $eliminations = $this->eliminationFigures($run);

        $columns = [
            'name' => ['label' => 'Akun', 'type' => ReportTable::TEXT],
        ];
        foreach ($entities as $entity) {
            $columns['e_'.$entity->source_code] = [
                'label' => $entity->source_name,
                'type' => ReportTable::MONEY,
            ];
        }
        $columns['sum'] = ['label' => 'Jumlah', 'type' => ReportTable::MONEY];
        $columns['elimination'] = ['label' => 'Eliminasi', 'type' => ReportTable::MONEY];
        $columns['consolidated'] = ['label' => 'Konsolidasi', 'type' => ReportTable::MONEY];

        $rows = [];
        $subtotals = [];
        $cumulative = [];
        foreach ([
            Account::ASSET => ['ASET', 'Jumlah Aset'],
            Account::LIABILITY => ['LIABILITAS', 'Jumlah Liabilitas'],
            Account::EQUITY => ['EKUITAS', 'Jumlah Ekuitas'],
            Account::REVENUE => ['PENDAPATAN', 'Jumlah Pendapatan'],
            Account::COGS => ['HARGA POKOK PENJUALAN', 'Jumlah Harga Pokok Penjualan'],
            Account::EXPENSE => ['BEBAN', 'Jumlah Beban'],
        ] as $type => [$heading, $subtotalLabel]) {
            $kelompok = array_filter($accounts, fn (array $a): bool => $a['type'] === $type);
            if ($kelompok === []) {
                continue;
            }

            $rows[] = $this->row($entities, $heading, null, null, 'section');
            $jumlahKelompok = $this->zeroes($entities);
            $jumlahKumulatif = $this->zeroes($entities);

            foreach ($kelompok as $code => $akun) {
                $values = [];
                $kumulatif = [];
                foreach ($entities as $entity) {
                    $values[$entity->source_code] = $perEntity[$entity->source_company_id][$code] ?? BigDecimal::zero();
                    $kumulatif[$entity->source_code] = $perEntityClosing[$entity->source_company_id][$code] ?? BigDecimal::zero();
                }
                $elimination = $eliminations[$code] ?? BigDecimal::zero();
                $rows[] = $this->row($entities, $code.' — '.$akun['name'], $values, $elimination, 'item');
                $jumlahKelompok = $this->add($jumlahKelompok, $values, $elimination);
                $jumlahKumulatif = $this->add($jumlahKumulatif, $kumulatif, $elimination);
            }

            $rows[] = $this->row($entities, $subtotalLabel,
                $jumlahKelompok['values'], $jumlahKelompok['elimination'], 'subtotal');
            $subtotals[$type] = $jumlahKelompok;
            $cumulative[$type] = $jumlahKumulatif;
        }

        /*
         * Tiga baris terakhir inilah gunanya kertas kerja. Dua yang pertama memperlihatkan laba tiap
         * entitas — periode ini dan kumulatif — dan yang ketiga memeriksa bahwa buku tiap entitas,
         * serta hasil konsolidasinya, benar seimbang. Kolom entitas yang tidak nol di baris
         * pemeriksaan langsung menunjuk entitas penyebabnya, dan itu satu-satunya cara ketimpangan
         * bisa ditelusuri tanpa membuka buku tiap entitas satu per satu.
         */
        $laba = $this->combine($entities, [
            [$subtotals[Account::REVENUE] ?? null, 1],
            [$subtotals[Account::COGS] ?? null, -1],
            [$subtotals[Account::EXPENSE] ?? null, -1],
        ]);
        $rows[] = $this->row($entities, 'LABA (RUGI) PERIODE INI', $laba['values'], $laba['elimination'], 'result');

        $labaKumulatif = $this->combine($entities, [
            [$cumulative[Account::REVENUE] ?? null, 1],
            [$cumulative[Account::COGS] ?? null, -1],
            [$cumulative[Account::EXPENSE] ?? null, -1],
        ]);
        $rows[] = $this->row($entities, 'Laba (rugi) kumulatif sejak jurnal pertama',
            $labaKumulatif['values'], $labaKumulatif['elimination'], 'subtotal');

        $periksa = $this->combine($entities, [
            [$cumulative[Account::ASSET] ?? null, 1],
            [$cumulative[Account::LIABILITY] ?? null, -1],
            [$cumulative[Account::EQUITY] ?? null, -1],
            [$labaKumulatif, -1],
        ]);
        $rows[] = $this->row($entities, 'PEMERIKSAAN: Aset − Liabilitas − Ekuitas − Laba kumulatif (harus nol)',
            $periksa['values'], $periksa['elimination'], 'result');

        $timpang = [];
        foreach ($entities as $entity) {
            if ($entity->out_of_balance) {
                $timpang[] = $entity->source_name;
            }
        }
        $kosong = [];
        foreach ($entities as $entity) {
            if (! $entity->hasData()) {
                $kosong[] = $entity->source_name;
            }
        }

        return new ReportTable(
            key: 'konsolidasi-kertas-kerja',
            title: 'Kertas Kerja Konsolidasi',
            subtitle: $run->describe(),
            columns: $columns,
            rows: $rows,
            totals: null,
            filters: $this->filters($run),
            summary: [
                ['label' => 'Entitas', 'value' => (string) count($entities), 'type' => ReportTable::NUMBER],
                ['label' => 'Ayat eliminasi', 'value' => (string) ConsolidationAdjustment::query()
                    ->where('run_id', $run->id)->count(), 'type' => ReportTable::NUMBER],
                ['label' => 'Laba (Rugi) Konsolidasi periode ini',
                    'value' => (string) $this->consolidated($laba)->toScale(2), 'type' => ReportTable::MONEY],
            ],
            notes: $this->notes($run, $timpang, $kosong, [
                'Akun neraca memakai saldo akhir periode; akun laba rugi memakai mutasi selama periode — '
                    .'persis angka yang masuk ke Neraca dan Laba Rugi konsolidasi.',
                'Baris pemeriksaan memakai laba KUMULATIF, bukan laba periode ini. Karena tutup buku '
                    .'tahunan belum tersedia, aset per tanggal akhir memuat seluruh laba sejak jurnal '
                    .'pertama; memeriksanya terhadap laba satu bulan akan selalu memberi selisih yang '
                    .'bukan ketimpangan.',
                'Kolom Eliminasi berisi ayat yang dientri di level konsolidasi, bukan hasil pengenalan otomatis.',
            ]),
        );
    }

    /**
     * Proses konsolidasi yang mewakili satu rentang tanggal.
     *
     * Dipakai paket laporan terjadwal, yang berbicara dalam rentang tanggal sementara modul ini
     * berbicara dalam proses. Kecocokan persis didahulukan; kalau tidak ada, proses terakhir yang
     * periodenya sudah berakhir pada atau sebelum tanggal akhir filter — supaya jadwal "kirim LK grup
     * tiap tanggal 3" tetap mengirim angka bulan yang baru selesai, bukan tidak mengirim apa pun.
     */
    public function runForPeriod(CarbonImmutable $from, CarbonImmutable $to): ?ConsolidationRun
    {
        $exact = ConsolidationRun::query()->with('group')
            ->whereDate('period_start', $from->format('Y-m-d'))
            ->whereDate('period_end', $to->format('Y-m-d'))
            ->first();
        if ($exact !== null) {
            return $exact;
        }

        return ConsolidationRun::query()->with('group')
            ->whereDate('period_end', '<=', $to->format('Y-m-d'))
            ->orderByDesc('period_end')->orderByDesc('created_at')
            ->first();
    }

    /**
     * Tabel kosong yang MENJELASKAN kenapa ia kosong.
     *
     * Paket laporan terjadwal yang mengirim tabel kosong tanpa keterangan membuat penerimanya
     * menyimpulkan bisnisnya sedang nol, dan itu kesalahan yang jauh lebih mahal daripada email yang
     * tidak terkirim.
     */
    public function missing(string $title, CarbonImmutable $from, CarbonImmutable $to): ReportTable
    {
        return new ReportTable(
            key: 'konsolidasi-kosong',
            title: $title,
            columns: ['name' => ['label' => 'Keterangan', 'type' => ReportTable::TEXT]],
            rows: [],
            totals: null,
            filters: ['Periode' => $from->format('d M Y').' – '.$to->format('d M Y')],
            notes: [
                'Belum ada proses konsolidasi untuk periode ini maupun periode sebelumnya, jadi tidak '
                    .'ada angka yang bisa ditampilkan. Buat prosesnya lebih dulu, lalu tarik saldo entitas.',
                'Tabel kosong di sini BUKAN berarti angkanya nol.',
            ],
        );
    }

    /** Neraca konsolidasi (CON-07). */
    public function balanceSheet(ConsolidationRun $run): ReportTable
    {
        return $this->statements->balanceSheetFrom(
            // Kumulatif, bukan mutasi periode: neraca adalah potret per tanggal, dan baris "Laba
            // (Rugi) Berjalan" yang menyeimbangkannya memuat seluruh laba yang belum ditutup.
            kini: $this->aggregate($run, hybrid: false),
            dulu: null,
            key: 'konsolidasi-neraca',
            title: 'Neraca Konsolidasi',
            labelKini: 'Per '.$run->period_end->format('d M Y'),
            labelDulu: null,
            filters: $this->filters($run),
            extraNotes: $this->notes($run, $this->outOfBalanceNames($run), $this->emptyNames($run), [
                'Tanpa kolom pembanding: pembanding memerlukan hasil konsolidasi periode sebelumnya '
                    .'beserta eliminasinya, dan menampilkan penjumlahan entitas tanpa eliminasi di '
                    .'kolom itu akan membuat dua kolom yang tidak sebanding diletakkan berdampingan.',
            ]),
            subtitle: $run->describe(),
        );
    }

    /** Laba Rugi konsolidasi (CON-07). */
    public function incomeStatement(ConsolidationRun $run): ReportTable
    {
        return $this->statements->incomeStatementFrom(
            saldo: $this->aggregate($run, hybrid: true),
            key: 'konsolidasi-laba-rugi',
            title: 'Laporan Laba Rugi Konsolidasi',
            subtitle: $run->describe(),
            filters: $this->filters($run),
            extraNotes: $this->notes($run, $this->outOfBalanceNames($run), $this->emptyNames($run)),
        );
    }

    /**
     * Saldo gabungan seluruh entitas sesudah eliminasi, berbentuk sama dengan
     * `FinancialStatements::balances()` sehingga laporannya bisa dibangun fungsi yang sama.
     *
     * `$hybrid` true memakai mutasi periode untuk akun laba rugi dan saldo akhir untuk akun neraca —
     * bentuk yang dibutuhkan Laba Rugi. False memakai saldo akhir untuk SEMUA akun — bentuk yang
     * dibutuhkan Neraca, karena di sanalah laba kumulatif menjadi penyeimbangnya.
     *
     * @return array<string, array{code: string, name: string, type: string, amount: BigDecimal}>
     */
    public function aggregate(ConsolidationRun $run, bool $hybrid = true): array
    {
        $out = [];
        foreach ($this->accountIndex($run) as $code => $akun) {
            $out[$code] = [
                'code' => $code,
                'name' => $akun['name'],
                'type' => $akun['type'],
                'amount' => BigDecimal::zero(),
            ];
        }

        foreach ($this->balanceRows($run) as $row) {
            $code = $row->target_code;
            if (! isset($out[$code])) {
                continue;
            }
            $out[$code]['amount'] = $out[$code]['amount']->plus($this->figure($row, $hybrid));
        }

        foreach ($this->eliminationFigures($run) as $code => $amount) {
            if (isset($out[$code])) {
                $out[$code]['amount'] = $out[$code]['amount']->plus($amount);
            }
        }

        return $out;
    }

    /**
     * Daftar akun konsolidasi: kode → nama & jenis, urut kode.
     *
     * Bila satu kode dipakai dengan JENIS akun yang berbeda di dua entitas, jenis pertama (urut kode
     * entitas) yang dipakai dan selisihnya akan terlihat di baris pemeriksaan. Itu bukan diam-diam:
     * `conflicts()` melaporkannya dan laporannya mencantumkannya sebagai peringatan, karena di situlah
     * aritmetikanya memang salah — satu akun tidak bisa menjadi aset di satu entitas dan beban di
     * entitas lain lalu dijumlahkan.
     *
     * @return array<string, array{name: string, type: string}>
     */
    private function accountIndex(ConsolidationRun $run): array
    {
        $out = [];
        foreach ($this->balanceRows($run) as $row) {
            $out[$row->target_code] ??= ['name' => $row->target_name, 'type' => $row->target_type];
        }
        foreach (ConsolidationAdjustment::query()->with(['debitAccount', 'creditAccount'])
            ->where('run_id', $run->id)->get() as $adjustment) {
            foreach ([$adjustment->debitAccount, $adjustment->creditAccount] as $account) {
                if ($account !== null) {
                    $out[$account->code] ??= ['name' => $account->name, 'type' => $account->type];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Kode akun yang jenisnya berbeda antar entitas — tempat aritmetika konsolidasi memang salah.
     *
     * @return list<string>
     */
    public function conflicts(ConsolidationRun $run): array
    {
        $seen = [];
        $bad = [];
        foreach ($this->balanceRows($run) as $row) {
            $code = $row->target_code;
            if (isset($seen[$code]) && $seen[$code] !== $row->target_type) {
                $bad[$code] = true;
            }
            $seen[$code] ??= $row->target_type;
        }

        return array_keys($bad);
    }

    /**
     * Angka per entitas per kode akun.
     *
     * @return array<string, array<string, BigDecimal>>
     */
    private function perEntityFigures(ConsolidationRun $run, bool $hybrid = true): array
    {
        $out = [];
        foreach ($this->balanceRows($run) as $row) {
            $current = $out[$row->source_company_id][$row->target_code] ?? BigDecimal::zero();
            $out[$row->source_company_id][$row->target_code] = $current->plus($this->figure($row, $hybrid));
        }

        return $out;
    }

    /**
     * Eliminasi per kode akun, bertanda menurut kelompok akunnya.
     *
     * @return array<string, BigDecimal>
     */
    private function eliminationFigures(ConsolidationRun $run): array
    {
        $out = [];
        $rows = ConsolidationAdjustment::query()->with(['debitAccount', 'creditAccount'])
            ->where('run_id', $run->id)->get();

        foreach ($rows as $row) {
            $amount = BigDecimal::of($row->amount);
            foreach ([[$row->debitAccount, 'debit'], [$row->creditAccount, 'credit']] as [$account, $side]) {
                if ($account === null) {
                    continue;
                }
                $debitGroup = in_array($account->type, Account::DEBIT_GROUPS, true);
                // Debit menambah kelompok debit dan mengurangi kelompok kredit; kredit sebaliknya.
                $signed = ($side === 'debit') === $debitGroup ? $amount : $amount->negated();
                $current = $out[$account->code] ?? BigDecimal::zero();
                $out[$account->code] = $current->plus($signed);
            }
        }

        return $out;
    }

    /**
     * Angka satu baris snapshot.
     *
     * `$hybrid`: akun neraca memakai saldo akhir, akun laba rugi memakai mutasi periode. Sebaliknya,
     * semuanya memakai saldo akhir — yang dibutuhkan neraca, dan satu-satunya bentuk yang membuatnya
     * seimbang selama tutup buku tahunan belum ada.
     */
    private function figure(ConsolidationBalance $row, bool $hybrid = true): BigDecimal
    {
        if (! $hybrid) {
            return BigDecimal::of($row->closing);
        }

        return BigDecimal::of(in_array($row->target_type, Account::BALANCE_SHEET, true)
            ? $row->closing
            : $row->period);
    }

    /** @return Collection<int, ConsolidationBalance> */
    private function balanceRows(ConsolidationRun $run): Collection
    {
        /** @var Collection<int, ConsolidationBalance> $rows */
        $rows = ConsolidationBalance::query()->where('run_id', $run->id)
            ->orderBy('target_code')->get();

        return $rows;
    }

    /** @return list<ConsolidationEntity> */
    private function entities(ConsolidationRun $run): array
    {
        return ConsolidationEntity::query()->where('run_id', $run->id)
            ->orderBy('sequence')->get()->all();
    }

    /** @return list<string> */
    private function outOfBalanceNames(ConsolidationRun $run): array
    {
        $out = [];
        foreach ($this->entities($run) as $entity) {
            if ($entity->out_of_balance) {
                $out[] = $entity->source_name;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function emptyNames(ConsolidationRun $run): array
    {
        $out = [];
        foreach ($this->entities($run) as $entity) {
            if (! $entity->hasData()) {
                $out[] = $entity->source_name;
            }
        }

        return $out;
    }

    /**
     * @param  list<ConsolidationEntity>  $entities
     * @param  array<string, BigDecimal>|null  $values
     * @return array<string, string|null>
     */
    private function row(array $entities, string $name, ?array $values, ?BigDecimal $elimination, string $style): array
    {
        $row = ['name' => $name];
        $jumlah = BigDecimal::zero();
        foreach ($entities as $entity) {
            $value = $values[$entity->source_code] ?? null;
            $row['e_'.$entity->source_code] = $value === null ? null : (string) $value->toScale(2);
            $jumlah = $jumlah->plus($value ?? BigDecimal::zero());
        }

        if ($values === null) {
            $row['sum'] = null;
            $row['elimination'] = null;
            $row['consolidated'] = null;
        } else {
            $elim = $elimination ?? BigDecimal::zero();
            $row['sum'] = (string) $jumlah->toScale(2);
            $row['elimination'] = (string) $elim->toScale(2);
            $row['consolidated'] = (string) $jumlah->plus($elim)->toScale(2);
        }
        $row['_style'] = $style;

        return $row;
    }

    /**
     * @param  list<ConsolidationEntity>  $entities
     * @return array{values: array<string, BigDecimal>, elimination: BigDecimal}
     */
    private function zeroes(array $entities): array
    {
        $values = [];
        foreach ($entities as $entity) {
            $values[$entity->source_code] = BigDecimal::zero();
        }

        return ['values' => $values, 'elimination' => BigDecimal::zero()];
    }

    /**
     * @param  array{values: array<string, BigDecimal>, elimination: BigDecimal}  $akumulasi
     * @param  array<string, BigDecimal>  $values
     * @return array{values: array<string, BigDecimal>, elimination: BigDecimal}
     */
    private function add(array $akumulasi, array $values, BigDecimal $elimination): array
    {
        foreach ($values as $code => $value) {
            $akumulasi['values'][$code] = ($akumulasi['values'][$code] ?? BigDecimal::zero())->plus($value);
        }
        $akumulasi['elimination'] = $akumulasi['elimination']->plus($elimination);

        return $akumulasi;
    }

    /**
     * @param  list<ConsolidationEntity>  $entities
     * @param  list<array{0: array{values: array<string, BigDecimal>, elimination: BigDecimal}|null, 1: int}>  $parts
     * @return array{values: array<string, BigDecimal>, elimination: BigDecimal}
     */
    private function combine(array $entities, array $parts): array
    {
        $out = $this->zeroes($entities);
        foreach ($parts as [$part, $sign]) {
            if ($part === null) {
                continue;
            }
            foreach ($part['values'] as $code => $value) {
                $contribution = $sign < 0 ? $value->negated() : $value;
                $out['values'][$code] = ($out['values'][$code] ?? BigDecimal::zero())->plus($contribution);
            }
            $out['elimination'] = $out['elimination']->plus(
                $sign < 0 ? $part['elimination']->negated() : $part['elimination']
            );
        }

        return $out;
    }

    /** @param  array{values: array<string, BigDecimal>, elimination: BigDecimal}  $part */
    private function consolidated(array $part): BigDecimal
    {
        $jumlah = BigDecimal::zero();
        foreach ($part['values'] as $value) {
            $jumlah = $jumlah->plus($value);
        }

        return $jumlah->plus($part['elimination']);
    }

    /** @return array<string, string> */
    private function filters(ConsolidationRun $run): array
    {
        return array_filter([
            'Grup' => $run->group?->name,
            'Periode' => $run->periodLabel(),
            'Status' => ConsolidationRun::STATUS_LABEL[$run->status] ?? $run->status,
            'Ditarik' => $run->generated_at?->timezone(ReportAccess::timezone())
                ->translatedFormat('d M Y H:i'),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $outOfBalance
     * @param  list<string>  $empty
     * @param  list<string>  $extra
     * @return list<string>
     */
    private function notes(ConsolidationRun $run, array $outOfBalance, array $empty, array $extra = []): array
    {
        $conflicts = $this->conflicts($run);

        return array_values(array_filter([
            'Hanya jurnal yang sudah diposting di tiap entitas yang dihitung; jurnal draft tidak ikut.',
            'Konsolidasi MANAJERIAL: penjumlahan entitas ditambah eliminasi, tanpa kepemilikan '
                .'minoritas (NCI) dan tanpa persentase kepemilikan. Jangan dipakai sebagai laporan '
                .'konsolidasi statutory tanpa dibahas lebih dulu dengan akuntan.',
            ...$extra,
            $run->generated_at === null
                ? 'PERINGATAN: saldo entitas belum pernah ditarik untuk periode ini, jadi angka di atas belum ada isinya.'
                : null,
            $empty === [] ? null
                : 'Entitas tanpa satu pun jurnal terposting sampai tanggal ini: '.implode(', ', $empty)
                    .'. Entitas yang bukunya diisi manual (villa, retail) paling mudah tertinggal, '
                    .'dan angka nol di kolomnya tidak berarti tidak ada kegiatan.',
            $outOfBalance === [] ? null
                : 'PERINGATAN: buku besar entitas berikut tidak seimbang, sehingga konsolidasinya juga '
                    .'tidak akan seimbang: '.implode(', ', $outOfBalance).'. Perbaiki di entitasnya, '
                    .'bukan dengan ayat penyesuaian di level konsolidasi.',
            $conflicts === [] ? null
                : 'PERINGATAN: kode akun berikut dipakai dengan JENIS akun berbeda di dua entitas, '
                    .'sehingga penjumlahannya tidak punya arti: '.implode(', ', $conflicts)
                    .'. Petakan salah satunya ke akun konsolidasi yang benar.',
            'Ayat eliminasi hanya berlaku untuk periode ini. Eliminasi periode sebelumnya tidak '
                .'dibawa maju, sehingga laba kumulatif masih memuat transaksi antar entitas periode '
                .'lalu. Selama tutup buku tahunan dan pembawaan eliminasi belum ada, angka yang paling '
                .'bisa dipercaya adalah Laba Rugi konsolidasi periode ini.',
            'Eliminasi otomatis atas pasangan hutang–piutang antar entitas belum ada: ia menunggu'
                .'penandaan counterparty dari advis tagih dan transfer kas antar entitas. Sampai itu '
                .'ada, eliminasi dientri sebagai ayat berpasangan di level konsolidasi.',
        ]));
    }
}
