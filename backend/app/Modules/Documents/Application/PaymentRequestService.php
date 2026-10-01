<?php

namespace App\Modules\Documents\Application;

use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Documents\Domain\Models\PaymentRequestApproval;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Domain\Models\Supplier;
use App\Modules\Shared\Support\DocumentNumber;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SPPK: pengajuan pembayaran dengan persetujuan berjenjang (DOC-04, DOC-02, DOC-09, DOC-10).
 *
 * Inilah pengganti pemeriksaan nota manual. Empat aturan yang tidak dilonggarkan:
 *
 * 1. **Pengaju tidak boleh menyetujui pengajuannya sendiri** — sama persis dengan pemisahan tugas
 *    di jurnal. Orang yang meminta uang tidak boleh menjadi orang yang mengizinkannya keluar.
 * 2. **Jumlah tanda tangan dibekukan saat diajukan**, bukan dibaca ulang tiap kali seseorang
 *    menandatangani. Kalau matriks berubah di tengah jalan, dokumen yang sedang berjalan tetap
 *    memakai aturan yang berlaku saat ia diajukan — kalau tidak, pengajuan bisa "selesai" hanya
 *    karena seseorang melonggarkan matriks setelah tanda tangan pertama.
 * 3. **Tanda tangan harus berurutan** dari tingkat 1. Tingkat 2 yang menandatangani lebih dulu
 *    berarti atasan menyetujui sesuatu yang belum diperiksa bawahannya.
 * 4. **Nilai dan akun beban terkunci begitu diajukan.** Menyetujui Rp5 juta lalu nilainya berubah
 *    jadi Rp50 juta adalah lubang yang paling sederhana dan paling mahal.
 */
class PaymentRequestService
{
    public function __construct(
        private readonly ApprovalMatrix $matrix,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{request_date?: string, due_date?: string|null, outlet_id?: string|null,
     *               supplier_id?: string|null, payee_name?: string|null, amount: string,
     *               expense_account_id: string, description: string}  $data
     */
    public function create(array $data, User $actor): PaymentRequest
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $date = CarbonImmutable::parse((string) ($data['request_date'] ?? 'today'))->startOfDay();
        $amount = $this->amount($data['amount'] ?? '0');
        $account = $this->postableAccount((string) ($data['expense_account_id'] ?? ''));
        [$supplierId, $payee] = $this->payee($data);

        return DB::transaction(function () use ($companyId, $data, $date, $amount, $account, $supplierId, $payee, $actor): PaymentRequest {
            $row = new PaymentRequest;
            $row->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'number' => DocumentNumber::nextForCompany('SPPK', $date),
                'request_date' => $date->format('Y-m-d'),
                'due_date' => isset($data['due_date']) && $data['due_date'] !== ''
                    ? CarbonImmutable::parse((string) $data['due_date'])->format('Y-m-d') : null,
                'outlet_id' => $this->outlet($data['outlet_id'] ?? null),
                'supplier_id' => $supplierId,
                'payee_name' => $payee,
                'amount' => (string) $amount->toScale(2),
                'paid_amount' => '0.00',
                'expense_account_id' => $account->id,
                'description' => $this->text($data['description'] ?? '', 300, 'description'),
                'status' => PaymentRequest::DRAFT,
                'required_levels' => 1,
                'requested_by' => $actor->id,
            ])->save();

            $this->audit->log('payment_request.created', $row,
                new: ['number' => $row->number, 'amount' => $row->amount, 'payee' => $payee], userId: $actor->id);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentRequest $request, array $data, User $actor): PaymentRequest
    {
        return DB::transaction(function () use ($request, $data, $actor): PaymentRequest {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $isi = [];
            if (array_key_exists('amount', $data)) {
                $isi['amount'] = (string) $this->amount($data['amount'])->toScale(2);
            }
            if (array_key_exists('expense_account_id', $data)) {
                $isi['expense_account_id'] = $this->postableAccount((string) $data['expense_account_id'])->id;
            }
            if (array_key_exists('description', $data)) {
                $isi['description'] = $this->text($data['description'], 300, 'description');
            }
            if (array_key_exists('due_date', $data)) {
                $isi['due_date'] = $data['due_date'] === null || $data['due_date'] === ''
                    ? null : CarbonImmutable::parse((string) $data['due_date'])->format('Y-m-d');
            }
            if (array_key_exists('outlet_id', $data)) {
                $isi['outlet_id'] = $this->outlet($data['outlet_id']);
            }
            if (array_key_exists('supplier_id', $data) || array_key_exists('payee_name', $data)) {
                [$isi['supplier_id'], $isi['payee_name']] = $this->payee($data);
            }

            $locked->forceFill($isi)->save();
            $this->audit->log('payment_request.updated', $locked, new: ['number' => $locked->number], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /** Ajukan untuk diperiksa. Jumlah tanda tangan yang dibutuhkan dibekukan di sini. */
    public function submit(PaymentRequest $request, User $actor): PaymentRequest
    {
        return DB::transaction(function () use ($request, $actor): PaymentRequest {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked);

            $required = $this->matrix->requiredFor(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of($locked->amount));

            $locked->forceFill([
                'status' => PaymentRequest::SUBMITTED,
                'required_levels' => count($required),
                'submitted_at' => now(),
                'rejected_by' => null, 'rejected_at' => null, 'reject_reason' => null,
            ])->save();

            $this->audit->log('payment_request.submitted', $locked, new: [
                'number' => $locked->number, 'amount' => $locked->amount, 'levels' => count($required),
            ], userId: $actor->id);

            return $locked->refresh();
        });
    }

    /** Tandatangani tingkat berikutnya. */
    public function approve(PaymentRequest $request, User $actor, ?string $note = null): PaymentRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): PaymentRequest {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentRequest::SUBMITTED) {
                throw new DocumentException('REQUEST_NOT_SUBMITTED',
                    'Hanya pengajuan yang sedang menunggu persetujuan yang dapat ditandatangani.', 409, field: 'status');
            }
            if ($locked->requested_by === $actor->id) {
                throw new DocumentException('SEGREGATION_OF_DUTIES',
                    'Pengajuan ini Anda sendiri yang membuat, jadi orang lain yang harus menyetujuinya.', 403);
            }

            $level = $locked->nextLevel();
            if ($level === null) {
                throw new DocumentException('ALREADY_APPROVED', 'Seluruh tanda tangan sudah lengkap.', 409);
            }
            /*
             * Satu orang tidak boleh mengisi dua tingkat sekaligus: dua tanda tangan dari orang yang
             * sama bukan dua pemeriksaan. Pemeriksaan ini sengaja didahulukan dari pemeriksaan peran:
             * "Anda sudah menandatangani" benar apa pun isi matriksnya, sedangkan "perannya harus
             * begini" akan menyesatkan — orangnya mengejar peran yang dituntut, padahal menambah
             * peran itu pun tidak akan membuat tanda tangan keduanya sah.
             */
            if ($locked->approvals()->where('approved_by', $actor->id)->exists()) {
                throw new DocumentException('ALREADY_SIGNED',
                    'Anda sudah menandatangani pengajuan ini di tingkat sebelumnya.', 409);
            }
            $role = $this->matrix->roleForLevel(ApprovalRule::PAYMENT_REQUEST, BigDecimal::of($locked->amount), $level);
            if (! $this->matrix->holdsRole($actor, $role)) {
                throw new DocumentException('APPROVAL_ROLE_REQUIRED',
                    "Tanda tangan tingkat {$level} untuk nilai ini harus dari peran \"".(ApprovalMatrix::roleOptions()[$role] ?? $role).'".',
                    403, field: 'role', details: ['level' => $level, 'role' => $role]);
            }

            $tanda = new PaymentRequestApproval;
            $tanda->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $locked->company_id,
                'payment_request_id' => $locked->id,
                'level' => $level,
                'role' => $role,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 300),
            ])->save();

            $lengkap = $level >= $locked->required_levels;
            if ($lengkap) {
                $locked->forceFill(['status' => PaymentRequest::APPROVED, 'approved_at' => now()])->save();
            }

            $this->audit->log('payment_request.approved', $locked, new: [
                'number' => $locked->number, 'level' => $level, 'role' => $role, 'final' => $lengkap,
            ], reason: $note, userId: $actor->id);

            return $locked->refresh();
        });
    }

    /** Tolak — kembali ke draft agar pengaju bisa memperbaiki. */
    public function reject(PaymentRequest $request, User $actor, string $reason): PaymentRequest
    {
        $text = trim($reason);
        if (mb_strlen($text) < 3) {
            throw new DocumentException('REJECT_REASON_REQUIRED',
                'Alasan penolakan wajib diisi — pengaju perlu tahu apa yang harus diperbaiki.', 422, field: 'reason');
        }

        return DB::transaction(function () use ($request, $actor, $text): PaymentRequest {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentRequest::SUBMITTED) {
                throw new DocumentException('REQUEST_NOT_SUBMITTED',
                    'Hanya pengajuan yang sedang menunggu persetujuan yang dapat ditolak.', 409, field: 'status');
            }

            // Tanda tangan yang sudah terkumpul dibuang: dokumen yang kembali ke draft boleh berubah,
            // dan tanda tangan atas isi yang lama tidak berlaku untuk isi yang baru.
            $locked->approvals()->delete();
            $locked->forceFill([
                'status' => PaymentRequest::DRAFT,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'reject_reason' => mb_substr($text, 0, 300),
            ])->save();

            $this->audit->log('payment_request.rejected', $locked,
                new: ['number' => $locked->number], reason: $text, userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function cancel(PaymentRequest $request, User $actor, string $reason): PaymentRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): PaymentRequest {
            /** @var PaymentRequest $locked */
            $locked = PaymentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (BigDecimal::of($locked->paid_amount)->isPositive()) {
                throw new DocumentException('REQUEST_ALREADY_PAID',
                    'Pengajuan yang sudah dibayar sebagian tidak dapat dibatalkan. Batalkan pembayarannya lewat jurnal balik.',
                    409, field: 'status');
            }
            $locked->forceFill(['status' => PaymentRequest::CANCELLED, 'reject_reason' => mb_substr(trim($reason), 0, 300)])->save();
            $this->audit->log('payment_request.cancelled', $locked,
                new: ['number' => $locked->number], reason: $reason, userId: $actor->id);

            return $locked->refresh();
        });
    }

    public function delete(PaymentRequest $request, User $actor): void
    {
        $this->assertEditable($request);
        $this->audit->log('payment_request.deleted', $request, old: ['number' => $request->number], userId: $actor->id);
        $request->delete();
    }

    private function assertEditable(PaymentRequest $request): void
    {
        if ($request->isEditable()) {
            return;
        }

        throw new DocumentException('REQUEST_NOT_EDITABLE',
            $request->status === PaymentRequest::SUBMITTED
                ? 'Pengajuan ini sedang menunggu persetujuan dan tidak dapat diubah. Minta pemeriksa menolaknya lebih dulu.'
                : 'Pengajuan yang sudah disetujui atau dibayar tidak dapat diubah.',
            409, field: 'status');
    }

    private function amount(mixed $value): BigDecimal
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || BigDecimal::of($text)->isZero()) {
            throw new DocumentException('REQUEST_AMOUNT', 'Nilai pengajuan harus angka lebih dari nol.', 422, field: 'amount');
        }

        return BigDecimal::of($text);
    }

    private function postableAccount(string $id): Account
    {
        /** @var Account|null $account */
        $account = Account::query()->find($id);
        if ($account === null || ! $account->is_postable || ! $account->is_active) {
            throw new DocumentException('ACCOUNT_NOT_POSTABLE',
                'Akun beban tidak dapat dijurnal. Pilih akun daun yang masih aktif.', 422, field: 'expense_account_id');
        }

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string}
     */
    private function payee(array $data): array
    {
        $supplierId = isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (string) $data['supplier_id'] : null;
        if ($supplierId !== null) {
            /** @var Supplier|null $supplier */
            $supplier = Supplier::query()->find($supplierId);
            if ($supplier === null) {
                throw new DocumentException('SUPPLIER_NOT_FOUND', 'Supplier tidak ditemukan.', 422, field: 'supplier_id');
            }

            // Nama disalin, bukan hanya dirujuk: dokumen lama harus tetap terbaca apa adanya
            // walaupun master supplier kelak diganti namanya atau dinonaktifkan.
            return [$supplier->id, mb_substr($supplier->name, 0, 150)];
        }

        return [null, $this->text($data['payee_name'] ?? '', 150, 'payee_name')];
    }

    private function outlet(mixed $outletId): ?string
    {
        if ($outletId === null || $outletId === '') {
            return null;
        }
        $exists = Outlet::query()->whereKey((string) $outletId)->exists();
        if (! $exists) {
            throw new DocumentException('OUTLET_NOT_FOUND', 'Outlet tidak ditemukan.', 422, field: 'outlet_id');
        }

        return (string) $outletId;
    }

    private function text(mixed $value, int $max, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if (mb_strlen($text) < 3) {
            throw new DocumentException('TEXT_REQUIRED', 'Isian ini wajib diisi minimal 3 huruf.', 422, field: $field);
        }

        return mb_substr($text, 0, $max);
    }
}
