<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use App\Modules\Treasury\Domain\Models\CashAccount;
use App\Modules\Treasury\Domain\Models\CashTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kas masuk, kas keluar, dan transfer antar rekening (CSH-02, CSH-03).
 *
 * Inilah pintu kas yang **bukan** pengeluaran bertanda tangan. Pengeluaran ke pihak ketiga tetap
 * lewat SPPK → advis bayar (Kelompok 4), karena di situlah batas wewenangnya berlaku. Yang masuk ke
 * sini adalah mutasi yang tidak punya pihak ketiga untuk disetujui: setoran hasil penjualan dari
 * laci kasir ke bank, pengisian kas kecil cabang, pemindahan antar rekening sendiri, penerimaan lain.
 *
 * Satu hal yang sengaja **tidak** dibuat gampang: tidak ada "kas keluar untuk membayar supplier"
 * di layar ini. Membolehkannya berarti menyediakan jalan pintas yang melewati seluruh persetujuan
 * SPPK, dan jalan pintas yang tersedia akan dipakai.
 */
class CashTransactionService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{kind: string, transaction_date?: string, cash_account_id: string,
     *               contra_account_id?: string|null, counter_cash_account_id?: string|null,
     *               amount: string, outlet_id?: string|null, description: string,
     *               reference?: string|null}  $data
     */
    public function record(array $data, User $actor): CashTransaction
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $kind = $this->kind($data['kind'] ?? '');
        $date = CarbonImmutable::parse((string) ($data['transaction_date'] ?? 'today'))->startOfDay();
        $amount = $this->amount($data['amount'] ?? '0');
        $cash = $this->cashAccount((string) ($data['cash_account_id'] ?? ''), 'cash_account_id');
        $description = $this->text($data['description'] ?? '', 300, 'description');

        $counter = null;
        $contra = null;
        if ($kind === CashTransaction::TRANSFER) {
            $counter = $this->cashAccount((string) ($data['counter_cash_account_id'] ?? ''), 'counter_cash_account_id');
            if ($counter->id === $cash->id) {
                throw new TreasuryException('SELF_TRANSFER',
                    'Rekening asal dan tujuan tidak boleh sama.', 422, field: 'counter_cash_account_id');
            }
        } else {
            $contra = $this->postableAccount((string) ($data['contra_account_id'] ?? ''));
            if ($contra->id === $cash->account_id) {
                throw new TreasuryException('SELF_CONTRA',
                    'Akun lawan tidak boleh akun rekening itu sendiri. Untuk pindah antar rekening, pakai Transfer.',
                    422, field: 'contra_account_id');
            }
        }

        return DB::transaction(function () use (
            $companyId, $kind, $date, $amount, $cash, $counter, $contra, $data, $description, $actor
        ): CashTransaction {
            $outletId = $this->outlet($data['outlet_id'] ?? null) ?? $cash->outlet_id;
            $journal = $this->journals->create([
                'journal_date' => $date->format('Y-m-d'),
                'description' => $description,
                'source' => 'cash',
                'lines' => $this->lines($kind, $amount, $cash, $counter, $contra, $outletId, $description),
            ], $actor);

            $row = new CashTransaction;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany(CashTransaction::KIND_PREFIX[$kind], $date),
                'kind' => $kind,
                'transaction_date' => $date->format('Y-m-d'),
                'cash_account_id' => $cash->id,
                'contra_account_id' => $contra?->id,
                'counter_cash_account_id' => $counter?->id,
                'amount' => (string) $amount->toScale(2),
                'outlet_id' => $outletId,
                'description' => $description,
                'reference' => $this->optional($data['reference'] ?? null, 100),
                'journal_id' => $journal->id,
                'created_by' => $actor->id,
            ])->save();

            $this->audit->log('cash_transaction.recorded', $row, new: [
                'number' => $row->number, 'kind' => $kind, 'amount' => $row->amount, 'journal' => $journal->number,
            ], userId: $actor->id);

            return $row;
        });
    }

    /**
     * Baris jurnalnya.
     *
     * ```
     * masuk     Dr rekening          Cr akun lawan
     * keluar    Dr akun lawan        Cr rekening
     * transfer  Dr rekening tujuan   Cr rekening asal
     * ```
     *
     * @return list<array<string, mixed>>
     */
    private function lines(
        string $kind,
        BigDecimal $amount,
        CashAccount $cash,
        ?CashAccount $counter,
        ?Account $contra,
        ?string $outletId,
        string $description,
    ): array {
        $nilai = (string) $amount->toScale(2);
        $memo = mb_substr($description, 0, 300);

        [$debitAccount, $creditAccount] = match ($kind) {
            CashTransaction::IN => [$cash->account_id, $contra?->id],
            CashTransaction::OUT => [$contra?->id, $cash->account_id],
            default => [$counter?->account_id, $cash->account_id],
        };

        return [
            ['account_id' => $debitAccount, 'debit' => $nilai, 'credit' => '0', 'memo' => $memo, 'outlet_id' => $outletId],
            ['account_id' => $creditAccount, 'debit' => '0', 'credit' => $nilai, 'memo' => $memo, 'outlet_id' => $outletId],
        ];
    }

    private function kind(mixed $value): string
    {
        $kind = is_string($value) ? $value : '';
        if (! array_key_exists($kind, CashTransaction::KIND_LABEL)) {
            throw new TreasuryException('CASH_TX_KIND', 'Jenis transaksi harus kas masuk, kas keluar, atau transfer.',
                422, field: 'kind');
        }

        return $kind;
    }

    private function cashAccount(string $id, string $field): CashAccount
    {
        /** @var CashAccount|null $cash */
        $cash = CashAccount::query()->find($id);
        if ($cash === null || ! $cash->is_active) {
            throw new TreasuryException('CASH_ACCOUNT_NOT_FOUND',
                'Rekening kas/bank tidak ditemukan atau sudah nonaktif.', 422, field: $field);
        }

        return $cash;
    }

    private function postableAccount(string $id): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->find($id);
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new TreasuryException('ACCOUNT_NOT_POSTABLE',
                'Akun lawan tidak dapat dijurnal. Pilih akun daun yang masih aktif.', 422, field: 'contra_account_id');
        }

        return $account;
    }

    private function amount(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || BigDecimal::of($text)->isZero()) {
            throw new TreasuryException('CASH_AMOUNT', 'Nilai transaksi harus angka lebih dari nol.', 422, field: 'amount');
        }

        return BigDecimal::of($text);
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
            throw new TreasuryException('TEXT_REQUIRED', 'Keterangan wajib diisi minimal 3 huruf.', 422, field: $field);
        }

        return mb_substr($text, 0, $max);
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
