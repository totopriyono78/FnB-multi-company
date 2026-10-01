<?php

namespace App\Modules\Documents\Application;

use App\Modules\Accounting\Application\JournalService;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Documents\Domain\Models\PaymentAdvice;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Advis bayar (DOC-05): instruksi pembayaran atas SPPK yang sudah disetujui, berikut jurnalnya.
 *
 * Jurnalnya:
 *
 * ```
 * Dr  Akun dari SPPK      sebesar yang dibayar
 *     Cr  Kas/Bank          dari rekening yang dipilih
 * ```
 *
 * Akun yang didebit adalah **akun yang ditunjuk SPPK**, bukan akun beban yang dipaksakan. Itu
 * disengaja: untuk pembayaran langsung, SPPK menunjuk akun beban dan bebannya diakui saat dibayar;
 * nanti ketika faktur pembelian ada (Kelompok 5), SPPK cukup menunjuk Utang Usaha dan jurnal yang
 * sama persis berubah arti menjadi pelunasan hutang. Satu mekanisme, dua keadaan — tanpa menulis
 * ulang apa pun.
 *
 * Satu SPPK boleh dibayar beberapa kali: pembayaran sebagian adalah hal biasa, dan memaksa satu
 * SPPK = satu transfer hanya membuat orang memecah pengajuannya untuk menghindari sistem.
 */
class PaymentAdviceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{paid_on?: string, amount: string, bank_account_id: string,
     *               reference?: string|null, note?: string|null}  $data
     */
    public function issue(PaymentRequest $request, array $data, User $actor): PaymentAdvice
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $date = CarbonImmutable::parse((string) ($data['paid_on'] ?? 'today'))->startOfDay();
        $bank = $this->postableAccount((string) ($data['bank_account_id'] ?? ''), 'bank_account_id');

        return DB::transaction(function () use ($request, $data, $companyId, $date, $bank, $actor): PaymentAdvice {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PaymentRequest::APPROVED, PaymentRequest::PAID], true)) {
                throw new DocumentException('REQUEST_NOT_APPROVED',
                    'Hanya pengajuan yang sudah disetujui lengkap yang dapat dibayar.', 409, field: 'status');
            }

            $amount = $this->amount($data['amount'] ?? '0');
            $sisa = $locked->outstanding();
            if ($amount->isGreaterThan($sisa)) {
                throw new DocumentException('ADVICE_EXCEEDS_REQUEST',
                    'Nilai pembayaran melebihi sisa pengajuan (sisa '.$sisa->toScale(2).').',
                    422, field: 'amount', details: ['outstanding' => (string) $sisa->toScale(2)]);
            }

            $journal = $this->journals->create([
                'journal_date' => $date->format('Y-m-d'),
                'description' => 'Pembayaran '.$locked->number.' — '.$locked->payee_name,
                'source' => 'payment',
                'lines' => [
                    ['account_id' => $locked->expense_account_id, 'debit' => (string) $amount->toScale(2), 'credit' => '0',
                        'memo' => mb_substr($locked->description, 0, 300), 'outlet_id' => $locked->outlet_id],
                    ['account_id' => $bank->id, 'debit' => '0', 'credit' => (string) $amount->toScale(2),
                        'memo' => 'Pembayaran ke '.$locked->payee_name, 'outlet_id' => $locked->outlet_id],
                ],
            ], $actor);

            $advice = new PaymentAdvice;
            $advice->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('AB', $date),
                'payment_request_id' => $locked->id,
                'paid_on' => $date->format('Y-m-d'),
                'amount' => (string) $amount->toScale(2),
                'bank_account_id' => $bank->id,
                'reference' => $this->text($data['reference'] ?? null, 100),
                'note' => $this->text($data['note'] ?? null, 300),
                'journal_id' => $journal->id,
                'created_by' => $actor->id,
            ])->save();

            $terbayar = BigDecimal::of($locked->paid_amount)->plus($amount);
            $locked->forceFill([
                'paid_amount' => (string) $terbayar->toScale(2),
                // "Dibayar" berarti seluruh nilainya sudah diinstruksikan, bukan bahwa jurnalnya
                // sudah diposting — posting adalah urusan pembukuan, bukan urusan uangnya.
                'status' => $terbayar->isGreaterThanOrEqualTo($locked->amount) ? PaymentRequest::PAID : $locked->status,
            ])->save();

            $this->audit->log('payment_advice.issued', $advice, new: [
                'number' => $advice->number, 'request' => $locked->number,
                'amount' => $advice->amount, 'journal' => $journal->number,
            ], userId: $actor->id);

            return $advice;
        });
    }

    private function amount(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || BigDecimal::of($text)->isZero()) {
            throw new DocumentException('ADVICE_AMOUNT', 'Nilai pembayaran harus angka lebih dari nol.', 422, field: 'amount');
        }

        return BigDecimal::of($text);
    }

    private function postableAccount(string $id, string $field): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->find($id);
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new DocumentException('ACCOUNT_NOT_POSTABLE',
                'Rekening sumber tidak dapat dijurnal.', 422, field: $field);
        }

        return $account;
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
