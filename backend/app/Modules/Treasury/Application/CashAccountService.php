<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Domain\Models\CashAccount;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Master kas & bank (CSH-01).
 *
 * Satu rekening menunjuk tepat satu akun buku besar, dan akun itu tidak boleh dipakai rekening lain.
 * Aturan itu yang membuat kalimat "saldo rekening ini" punya arti: tanpanya, dua rekening bank yang
 * menumpang satu akun "Bank" hanya bisa dijumlahkan, tidak bisa dibedakan — dan rekonsiliasi bank
 * kehilangan dasarnya sama sekali.
 *
 * Konsekuensinya setiap rekening baru butuh akun buku besar sendiri. Supaya itu tidak menjadi
 * pekerjaan dua langkah yang mudah terlupa, layanan ini bisa **membuatkan akunnya** di bawah induk
 * yang dipilih, dengan kode kosong pertama yang tersedia.
 */
class CashAccountService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code?: string|null, name: string, kind?: string, bank_name?: string|null,
     *               account_number?: string|null, account_holder?: string|null,
     *               account_id?: string|null, parent_code?: string|null, outlet_id?: string|null,
     *               notes?: string|null}  $data
     */
    public function create(array $data): CashAccount
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $kind = $this->kind($data['kind'] ?? CashAccount::BANK);
        $name = $this->text($data['name'] ?? '', 100, 'name');

        return DB::transaction(function () use ($companyId, $data, $kind, $name): CashAccount {
            $account = $this->resolveAccount($data, $name);

            $row = new CashAccount;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'code' => $this->code($data['code'] ?? null, $name),
                'name' => $name,
                'kind' => $kind,
                'bank_name' => $kind === CashAccount::BANK ? $this->optional($data['bank_name'] ?? null, 100) : null,
                'account_number' => $kind === CashAccount::BANK ? $this->optional($data['account_number'] ?? null, 50) : null,
                'account_holder' => $kind === CashAccount::BANK ? $this->optional($data['account_holder'] ?? null, 100) : null,
                'account_id' => $account->id,
                'outlet_id' => $this->outlet($data['outlet_id'] ?? null),
                'is_active' => true,
                'notes' => $this->optional($data['notes'] ?? null, 300),
                'created_by' => auth()->id(),
            ])->save();

            $this->audit->log('cash_account.created', $row, new: [
                'code' => $row->code, 'name' => $row->name, 'account' => $account->code,
            ]);

            return $row;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(CashAccount $cashAccount, array $data): CashAccount
    {
        return DB::transaction(function () use ($cashAccount, $data): CashAccount {
            $isi = [];
            if (array_key_exists('name', $data)) {
                $isi['name'] = $this->text($data['name'], 100, 'name');
            }
            foreach (['bank_name' => 100, 'account_number' => 50, 'account_holder' => 100, 'notes' => 300] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $isi[$field] = $this->optional($data[$field], $max);
                }
            }
            if (array_key_exists('outlet_id', $data)) {
                $isi['outlet_id'] = $this->outlet($data['outlet_id']);
            }
            if (array_key_exists('is_active', $data)) {
                $isi['is_active'] = (bool) $data['is_active'];
            }

            /*
             * Akun buku besar sebuah rekening TIDAK bisa diganti. Memindahkannya akan membuat mutasi
             * lama tetap di akun lama sementara laporannya membaca akun baru — rekening yang saldonya
             * benar hanya dari tanggal pindah, dan tidak ada tempat untuk mengatakan itu. Rekening
             * baru dengan akun baru jauh lebih jujur.
             */
            $cashAccount->forceFill($isi)->save();
            $this->audit->log('cash_account.updated', $cashAccount, new: ['code' => $cashAccount->code]);

            return $cashAccount->refresh();
        });
    }

    /**
     * Saldo buku rekening pada suatu tanggal, dari jurnal yang **sudah diposting**.
     *
     * Hanya yang terposting dihitung: saldo kas yang memuat jurnal draft adalah saldo yang bisa
     * berubah tanpa ada yang melakukan apa-apa.
     */
    public function balance(CashAccount $cashAccount, ?CarbonImmutable $asOf = null): BigDecimal
    {
        $row = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $cashAccount->account_id)
            ->where('journals.status', 'posted')
            ->when($asOf !== null, fn ($q) => $q->where('journals.journal_date', '<=', $asOf->format('Y-m-d')))
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) AS d, COALESCE(SUM(journal_lines.credit),0) AS c')
            ->first();

        return BigDecimal::of((string) ($row->d ?? '0'))->minus((string) ($row->c ?? '0'));
    }

    /** Daftar rekening berikut saldonya — jawaban atas "ada berapa uang kita, di mana saja". */
    public function positions(?CarbonImmutable $asOf = null): ReportTable
    {
        $per = $asOf ?? CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $rows = [];
        $total = BigDecimal::zero();

        /** @var iterable<CashAccount> $accounts */
        $accounts = CashAccount::query()->with(['account:id,code,name', 'outlet:id,name'])
            ->orderBy('kind')->orderBy('code')->get();

        foreach ($accounts as $cash) {
            $saldo = $this->balance($cash, $per);
            $total = $total->plus($saldo);
            $rows[] = [
                // Rekening nonaktif tetap ditampilkan selama saldonya belum nol — menyembunyikannya
                // berarti menyembunyikan uang. Statusnya ditulis di namanya supaya ikut terbawa ke
                // Excel dan PDF, bukan hanya terlihat sebagai warna di layar.
                'name' => $cash->label().($cash->is_active ? '' : ' (nonaktif)'),
                'kind' => CashAccount::KIND_LABEL[$cash->kind] ?? $cash->kind,
                // account_id tidak pernah kosong (relasi satu-satu yang wajib); outlet_id boleh kosong.
                'account' => $cash->account->code,
                'outlet' => $cash->outlet?->name ?: 'Entitas',
                'balance' => (string) $saldo->toScale(2),
            ];
        }

        return new ReportTable(
            key: 'posisi-kas',
            title: 'Posisi Kas & Bank',
            subtitle: 'Saldo buku per '.$per->translatedFormat('d M Y'),
            columns: [
                'name' => ['label' => 'Rekening', 'type' => ReportTable::TEXT],
                'kind' => ['label' => 'Jenis', 'type' => ReportTable::TEXT],
                'account' => ['label' => 'Akun', 'type' => ReportTable::TEXT],
                'outlet' => ['label' => 'Pemilik', 'type' => ReportTable::TEXT],
                'balance' => ['label' => 'Saldo buku', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['name' => 'JUMLAH', 'balance' => (string) $total->toScale(2)],
            notes: [
                'Saldo dihitung dari jurnal yang sudah diposting. Jurnal yang masih draft atau diajukan belum terhitung di sini.',
                'Saldo buku belum tentu sama dengan saldo rekening di bank — selisihnya ditemukan lewat rekonsiliasi bank.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAccount(array $data, string $name): Account
    {
        $chosen = isset($data['account_id']) && $data['account_id'] !== '' ? (string) $data['account_id'] : null;
        if ($chosen !== null) {
            /** @var Account|null $account */
            $account = Account::query()->find($chosen);
            if ($account === null || ! $account->is_postable || ! $account->is_active) {
                throw new TreasuryException('ACCOUNT_NOT_POSTABLE',
                    'Akun buku besar tidak dapat dijurnal. Pilih akun daun yang masih aktif.', 422, field: 'account_id');
            }
            $dipakai = CashAccount::query()->where('account_id', $account->id)->exists();
            if ($dipakai) {
                throw new TreasuryException('ACCOUNT_ALREADY_USED',
                    'Akun ini sudah dipakai rekening lain. Satu akun buku besar hanya untuk satu rekening, '
                    .'supaya saldo tiap rekening tetap bisa dihitung sendiri-sendiri.', 422, field: 'account_id');
            }

            return $account;
        }

        return $this->createAccountUnder((string) ($data['parent_code'] ?? '1100'), $name);
    }

    /** Buat akun daun baru di bawah induk yang diminta, memakai kode kosong pertama. */
    private function createAccountUnder(string $parentCode, string $name): Account
    {
        /** @var Account|null $parent */
        $parent = Account::query()->where('code', $parentCode)->first();
        if ($parent === null) {
            throw new TreasuryException('PARENT_ACCOUNT_MISSING',
                "Akun induk {$parentCode} tidak ada di bagan akun. Pasang template bagan akun lebih dulu.",
                422, field: 'parent_code');
        }

        $account = new Account;
        $account->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $parent->company_id,
            'code' => $this->nextCodeUnder($parentCode),
            'name' => mb_substr($name, 0, 100),
            'type' => $parent->type,
            'normal_balance' => Account::NORMAL[$parent->type],
            'parent_id' => $parent->id,
            'is_postable' => true,
            'is_active' => true,
            'is_system' => false,
        ])->save();

        return $account;
    }

    /**
     * Kode kosong PERTAMA di bawah induk, termasuk celah di tengah. Template memakai 1101, 1102,
     * 1103 dan 1110, jadi rekening pertama yang dibuatkan akun akan mendapat 1104 — mengisi celah,
     * bukan menumpuk di ujung. Dengan begitu kode rekening tetap berdekatan dengan induknya walau
     * template kelak menambah akun baru di bawahnya.
     */
    private function nextCodeUnder(string $parentCode): string
    {
        $dasar = (int) $parentCode;
        /** @var list<string> $terpakai */
        $terpakai = Account::query()->where('code', 'like', mb_substr($parentCode, 0, 2).'%')->pluck('code')->all();
        $ada = array_flip($terpakai);

        for ($i = $dasar + 1; $i < $dasar + 100; $i++) {
            $kode = (string) $i;
            if (! isset($ada[$kode])) {
                return $kode;
            }
        }

        throw new TreasuryException('ACCOUNT_CODE_EXHAUSTED',
            "Tidak ada kode akun kosong di bawah {$parentCode}. Buat akunnya sendiri di Bagan Akun, lalu pilih di sini.",
            422, field: 'parent_code');
    }

    private function kind(mixed $value): string
    {
        $kind = is_string($value) ? $value : '';
        if (! array_key_exists($kind, CashAccount::KIND_LABEL)) {
            throw new TreasuryException('CASH_KIND_UNKNOWN', 'Jenis rekening harus kas atau bank.', 422, field: 'kind');
        }

        return $kind;
    }

    private function code(mixed $value, string $name): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';
        if ($code === '') {
            $code = strtoupper(Str::slug(mb_substr($name, 0, 12), ''));
        }
        if ($code === '') {
            throw new TreasuryException('CASH_CODE_REQUIRED', 'Kode rekening wajib diisi.', 422, field: 'code');
        }
        if (CashAccount::query()->where('code', $code)->exists()) {
            throw new TreasuryException('CASH_CODE_TAKEN', "Kode rekening {$code} sudah dipakai.", 422, field: 'code');
        }

        return mb_substr($code, 0, 20);
    }

    private function outlet(mixed $outletId): ?string
    {
        if ($outletId === null || $outletId === '') {
            return null;
        }
        if (! Outlet::query()->whereKey((string) $outletId)->exists()) {
            throw new TreasuryException('OUTLET_NOT_FOUND', 'Outlet tidak ditemukan.', 422, field: 'outlet_id');
        }

        return (string) $outletId;
    }

    private function text(mixed $value, int $max, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < 3) {
            throw new TreasuryException('TEXT_REQUIRED', 'Isian ini wajib diisi minimal 3 huruf.', 422, field: $field);
        }

        return mb_substr($text, 0, $max);
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
