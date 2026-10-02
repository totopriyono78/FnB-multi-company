<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu periode rekening koran yang diimpor untuk dicocokkan (CSH-04).
 *
 * @property string $id
 * @property string $company_id
 * @property string $cash_account_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string|null $source_name
 * @property CarbonImmutable|null $locked_at
 * @property string|null $locked_by
 * @property string $created_by
 * @property-read Collection<int, BankStatementLine> $lines
 */
class BankStatement extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'opening_balance' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'locked_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<CashAccount, $this> */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
