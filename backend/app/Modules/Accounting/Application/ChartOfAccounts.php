<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Application\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bagan akun per entitas (ACC-01).
 *
 * Aturan yang dijaga di sini, dan alasannya:
 * - **Akun yang sudah bermutasi tidak boleh berubah artinya.** Jenis dan saldo normal mengubah cara
 *   seluruh riwayatnya dibaca; mengubahnya setelah ada jurnal berarti laporan tahun lalu diam-diam
 *   ikut berubah. Nama dan keterangan tetap boleh diperbaiki.
 * - **Akun bertanda system tidak boleh dihapus** — pemetaan jurnal otomatis mengacu padanya.
 * - **Akun yang punya mutasi atau anak tidak boleh dihapus**, hanya dinonaktifkan.
 */
class ChartOfAccounts
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Pasang template standar untuk satu company. Idempoten: kode yang sudah ada dilewati, sehingga
     * aman dijalankan ulang saat template bertambah.
     *
     * @return int jumlah akun yang benar-benar dibuat
     */
    public function installTemplate(): int
    {
        $companyId = app(TenantContext::class)->requireCompanyId();

        return DB::transaction(function () use ($companyId): int {
            $existing = Account::query()->pluck('id', 'code')->all();
            $created = 0;

            foreach (AccountTemplate::accounts() as $row) {
                if (isset($existing[$row['code']])) {
                    continue;
                }
                $account = new Account;
                $account->forceFill([
                    'id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'normal_balance' => $row['normal'] ?? Account::NORMAL[$row['type']],
                    'parent_id' => $row['parent'] === null ? null : ($existing[$row['parent']] ?? null),
                    'is_postable' => $row['postable'],
                    'is_active' => true,
                    'is_system' => $row['system'],
                ])->save();
                $existing[$row['code']] = $account->id;
                $created++;
            }

            if ($created > 0) {
                $this->audit->log('accounts.template_installed', null, new: ['created' => $created]);
            }

            return $created;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Account
    {
        $account = new Account;
        $account->fill($this->clean($data));
        $account->normal_balance = $this->normalFor($account->type, $data['normal_balance'] ?? null);
        $account->save();

        $this->audit->log('account.created', $account, new: ['code' => $account->code, 'name' => $account->name, 'type' => $account->type]);

        return $account;
    }

    /** @param  array<string, mixed>  $data */
    public function update(Account $account, array $data): Account
    {
        $before = ['code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'is_active' => $account->is_active];

        if ($this->hasEntries($account)) {
            foreach (['code', 'type'] as $locked) {
                if (array_key_exists($locked, $data) && (string) $data[$locked] !== (string) $account->{$locked}) {
                    throw new AccountingException(
                        'ACCOUNT_HAS_ENTRIES',
                        'Akun ini sudah punya jurnal, sehingga kode dan jenisnya tidak dapat diubah. Nonaktifkan akun ini dan buat akun baru bila memang perlu berubah.',
                        422, field: $locked,
                    );
                }
            }
        }

        $account->fill($this->clean($data));
        if (array_key_exists('normal_balance', $data) && ! $this->hasEntries($account)) {
            $account->normal_balance = $this->normalFor($account->type, $data['normal_balance']);
        }
        $account->save();

        $this->audit->log('account.updated', $account, old: $before, new: ['code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'is_active' => $account->is_active]);

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new AccountingException('ACCOUNT_IS_SYSTEM', 'Akun bawaan tidak dapat dihapus karena diacu jurnal otomatis. Nonaktifkan saja bila tidak dipakai.', 422);
        }
        if ($this->hasEntries($account)) {
            throw new AccountingException('ACCOUNT_HAS_ENTRIES', 'Akun yang sudah punya jurnal tidak dapat dihapus. Nonaktifkan saja.', 422);
        }
        if (Account::query()->where('parent_id', $account->id)->exists()) {
            throw new AccountingException('ACCOUNT_HAS_CHILDREN', 'Akun ini masih memiliki sub-akun.', 422);
        }

        $this->audit->log('account.deleted', $account, old: ['code' => $account->code, 'name' => $account->name]);
        $account->delete();
    }

    public function hasEntries(Account $account): bool
    {
        return JournalLine::query()->where('account_id', $account->id)->exists();
    }

    private function normalFor(string $type, mixed $requested): string
    {
        $value = is_string($requested) ? $requested : null;

        return in_array($value, ['debit', 'credit'], true) ? $value : Account::NORMAL[$type];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        foreach (['code', 'name', 'description'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }
}
