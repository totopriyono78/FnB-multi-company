<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\JournalMapping;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aturan "kejadian bisnis → akun" untuk jurnal otomatis (ACC-10).
 *
 * Bawaannya mengacu **kode akun** template, bukan id: id berbeda di tiap entitas, sedangkan kodenya
 * sama. Entitas yang bagan akunnya belum dipasang tidak bisa menjurnal otomatis — dan itu memang
 * dikatakan apa adanya, bukan diam-diam memakai akun pertama yang kebetulan cocok.
 */
class JournalMap
{
    public const REVENUE = 'revenue';

    public const SERVICE_CHARGE = 'service_charge';

    public const TAX = 'tax';

    public const DISCOUNT = 'discount';

    public const ROUNDING = 'rounding';

    public const REFUND = 'refund';

    public const MDR = 'mdr';

    public const CASH_VARIANCE = 'cash_variance';

    public const CASH_MOVEMENT = 'cash_movement';

    /** Potongan yang baru muncul saat dana settlement cair, di luar MDR yang diakui saat penjualan. */
    public const SETTLEMENT_FEE = 'settlement_fee';

    /** Slot pembayaran dibentuk dari metode: `payment.cash`, `payment.qris`, … */
    public static function paymentSlot(string $method): string
    {
        return 'payment.'.$method;
    }

    /**
     * Akun bawaan tiap slot, memakai KODE akun template.
     *
     * @return array<string, array{code: string, label: string, hint: string}>
     */
    public static function slots(): array
    {
        return [
            self::REVENUE => ['code' => '4103', 'label' => 'Pendapatan (bawaan)', 'hint' => 'Dipakai untuk kategori menu yang belum dipetakan sendiri.'],
            self::SERVICE_CHARGE => ['code' => '4201', 'label' => 'Service charge', 'hint' => 'Service charge yang ditagihkan ke pelanggan.'],
            self::TAX => ['code' => '2201', 'label' => 'Pajak keluaran (PB1)', 'hint' => 'Utang pajak restoran yang dipungut dari pelanggan.'],
            self::DISCOUNT => ['code' => '4301', 'label' => 'Diskon penjualan', 'hint' => 'Diskon baris maupun diskon transaksi.'],
            self::ROUNDING => ['code' => '4303', 'label' => 'Selisih pembulatan', 'hint' => 'Pembulatan Rp100 pada total tagihan.'],
            self::REFUND => ['code' => '4302', 'label' => 'Retur penjualan', 'hint' => 'Nilai yang dikembalikan ke pelanggan.'],
            self::MDR => ['code' => '6105', 'label' => 'Biaya transaksi (MDR)', 'hint' => 'Potongan penyedia pembayaran, diakui saat penjualan.'],
            self::CASH_VARIANCE => ['code' => '6106', 'label' => 'Selisih kas', 'hint' => 'Selisih hitungan laci saat tutup shift.'],
            self::CASH_MOVEMENT => ['code' => '1103', 'label' => 'Kas masuk/keluar belum dialokasikan', 'hint' => 'Penampung sementara; finance memindahkannya ke akun yang benar.'],
            self::SETTLEMENT_FEE => ['code' => '6105', 'label' => 'Biaya pencairan settlement', 'hint' => 'Potongan saat dana cair, di luar MDR yang sudah diakui saat penjualan.'],
            self::paymentSlot('cash') => ['code' => '1101', 'label' => 'Pembayaran tunai', 'hint' => 'Masuk ke laci kasir.'],
            self::paymentSlot('debit') => ['code' => '1201', 'label' => 'Pembayaran kartu debit', 'hint' => 'Piutang settlement sampai dana cair.'],
            self::paymentSlot('credit') => ['code' => '1201', 'label' => 'Pembayaran kartu kredit', 'hint' => 'Piutang settlement sampai dana cair.'],
            self::paymentSlot('qris') => ['code' => '1202', 'label' => 'Pembayaran QRIS', 'hint' => 'Piutang settlement sampai dana cair.'],
            self::paymentSlot('ewallet') => ['code' => '1202', 'label' => 'Pembayaran e-wallet', 'hint' => 'Piutang settlement sampai dana cair.'],
            self::paymentSlot('transfer') => ['code' => '1110', 'label' => 'Pembayaran transfer bank', 'hint' => 'Langsung ke rekening bank.'],
        ];
    }

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Isi pemetaan bawaan yang belum ada. Idempoten; aman dijalankan ulang saat slot bertambah.
     *
     * @return int jumlah baris yang dibuat
     */
    public function installDefaults(): int
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $accounts = Account::query()->pluck('id', 'code')->all();
        if ($accounts === []) {
            throw new AccountingException('CHART_OF_ACCOUNTS_EMPTY',
                'Bagan akun belum dipasang. Buka Akuntansi → Bagan Akun dan pasang template standar lebih dulu.', 422);
        }

        return DB::transaction(function () use ($companyId, $accounts): int {
            $existing = JournalMapping::query()->whereNull('ref_id')->pluck('slot')->all();
            $created = 0;
            foreach (self::slots() as $slot => $def) {
                if (in_array($slot, $existing, true) || ! isset($accounts[$def['code']])) {
                    continue;
                }
                $this->write($companyId, $slot, null, $accounts[$def['code']]);
                $created++;
            }
            if ($created > 0) {
                $this->audit->log('journal_mappings.defaults_installed', null, new: ['created' => $created]);
            }

            return $created;
        });
    }

    /** Ubah (atau buat) pemetaan satu slot. `$refId` null = aturan bawaan slot itu. */
    public function set(string $slot, ?string $refId, string $accountId): JournalMapping
    {
        $account = Account::query()->findOrFail($accountId);
        if (! $account->is_postable || ! $account->is_active) {
            throw new AccountingException('ACCOUNT_NOT_POSTABLE',
                "Akun {$account->label()} tidak dapat dijurnal, jadi tidak bisa dipakai sebagai tujuan pemetaan.", 422, field: 'account_id');
        }
        $companyId = app(TenantContext::class)->requireCompanyId();
        $row = $this->write($companyId, $slot, $refId, $accountId);

        $this->audit->log('journal_mapping.updated', $row, new: ['slot' => $slot, 'ref_id' => $refId, 'account' => $account->code]);

        return $row;
    }

    /**
     * Akun untuk satu slot. `$refId` dicoba lebih dulu, lalu jatuh ke bawaan slot.
     *
     * @param  bool  $required  bila true, slot yang tidak terpetakan menggagalkan penyusunan jurnal
     * @return ($required is true ? Account : Account|null)
     */
    public function account(string $slot, ?string $refId = null, bool $required = true): ?Account
    {
        $rows = $this->cache();
        $account = ($refId !== null ? ($rows[$slot.'|'.$refId] ?? null) : null) ?? ($rows[$slot.'|'] ?? null);

        if ($account === null && $required) {
            $label = self::slots()[$slot]['label'] ?? $slot;
            throw new AccountingException('MAPPING_MISSING',
                "Pemetaan akun untuk \"{$label}\" belum diatur. Buka Akuntansi → Pemetaan Akun untuk melengkapinya.",
                422, field: 'slot', details: ['slot' => $slot]);
        }

        return $account;
    }

    /**
     * Pemetaan satu company, dibaca sekali per instance.
     *
     * Disimpan di properti, bukan variabel `static`: satu penyusunan jurnal memanggil ini belasan
     * kali, sedangkan cache yang hidup lintas instance akan menyajikan pemetaan basi tepat setelah
     * seseorang mengubahnya di layar.
     *
     * @return array<string, Account> kunci `slot|refId`
     */
    private function cache(): array
    {
        $companyId = app(TenantContext::class)->requireCompanyId();

        return $this->cache[$companyId] ??= JournalMapping::query()->with('account')->get()
            ->mapWithKeys(fn (JournalMapping $m) => [$m->slot.'|'.($m->ref_id ?? '') => $m->account])
            ->filter()
            ->all();
    }

    /** @var array<string, array<string, Account>> */
    private array $cache = [];

    private function write(string $companyId, string $slot, ?string $refId, string $accountId): JournalMapping
    {
        /** @var JournalMapping|null $row */
        $row = JournalMapping::query()->where('slot', $slot)
            ->when($refId === null, fn ($q) => $q->whereNull('ref_id'), fn ($q) => $q->where('ref_id', $refId))
            ->first();

        if ($row === null) {
            $row = new JournalMapping;
            $row->forceFill(['id' => (string) Str::uuid7(), 'company_id' => $companyId, 'slot' => $slot, 'ref_id' => $refId]);
        }
        $row->forceFill(['account_id' => $accountId])->save();

        return $row;
    }
}
