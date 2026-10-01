<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jurnal umum (ACC-05). Setelah diposting bersifat final — trigger basis data menolak perubahannya.
 * Koreksi hanya lewat jurnal balik yang merujuk jurnal asal (ACC-07).
 *
 * @property string $id
 * @property string $company_id
 * @property string $number
 * @property CarbonImmutable $journal_date
 * @property string $period_id
 * @property string $description
 * @property string $status
 * @property string $source
 * @property string|null $source_key
 * @property string|null $reverses_journal_id
 * @property string|null $reversed_by_journal_id
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $posted_by
 * @property CarbonImmutable|null $posted_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $reject_reason
 */
class Journal extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const DRAFT = 'draft';

    /** Diajukan: angkanya dinyatakan siap, tetapi belum masuk buku besar (ACC-05). */
    public const SUBMITTED = 'submitted';

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    public const STATUS_LABEL = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Diajukan',
        self::POSTED => 'Diposting',
        self::REVERSED => 'Dibalik',
    ];

    /** Jurnal yang sudah masuk buku besar. `reversed` tetap dihitung — pembalikannya jurnal tersendiri. */
    public const IN_LEDGER = [self::POSTED, self::REVERSED];

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'journal_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<AccountingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<JournalAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(JournalAttachment::class)->orderBy('created_at');
    }

    /** @return BelongsTo<Journal, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    /** Masih boleh disunting isinya: hanya draft. */
    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPosted(): bool
    {
        return in_array($this->status, self::IN_LEDGER, true);
    }
}
