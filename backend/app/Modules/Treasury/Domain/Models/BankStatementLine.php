<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Accounting\Domain\Models\JournalLine;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris mutasi di rekening koran (CSH-04).
 *
 * Debet dan kredit disimpan di dua kolom karena begitulah bentuk rekening koran bank di Indonesia.
 * Menerjemahkannya menjadi satu kolom bertanda memaksa impor menebak arah uang, dan tebakan yang
 * salah baru ketahuan saat saldonya tidak cocok — jauh dari tempat kesalahannya dibuat.
 *
 * @property string $id
 * @property string $company_id
 * @property string $bank_statement_id
 * @property int $line_no
 * @property CarbonImmutable $value_date
 * @property string $description
 * @property string|null $reference
 * @property string $debit
 * @property string $credit
 * @property string|null $matched_journal_line_id
 * @property CarbonImmutable|null $matched_at
 * @property string|null $matched_by
 * @property string|null $match_mode
 * @property bool $is_ignored
 * @property string|null $ignore_reason
 */
class BankStatementLine extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'value_date' => 'immutable_date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'matched_at' => 'immutable_datetime',
            'is_ignored' => 'boolean',
        ];
    }

    /** @return BelongsTo<BankStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** @return BelongsTo<JournalLine, $this> */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_journal_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isMatched(): bool
    {
        return $this->matched_journal_line_id !== null;
    }

    /**
     * Nilai bertanda dari sudut pandang BUKU kita: uang masuk rekening (kredit di rekening koran)
     * adalah debit di buku besar kita. Pembalikan sudut pandang ini sumber kebingungan paling umum
     * saat merekonsiliasi, jadi ia dikerjakan sekali di sini, bukan berulang di tiap pemanggil.
     */
    public function bookSignedAmount(): BigDecimal
    {
        return BigDecimal::of($this->credit)->minus($this->debit);
    }
}
