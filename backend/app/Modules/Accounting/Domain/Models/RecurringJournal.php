<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Templat jurnal bulanan (ACC-06).
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $description
 * @property int $day_of_month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $is_active
 * @property CarbonImmutable|null $last_generated_on
 * @property array<int, array<string, mixed>> $lines
 * @property string $created_by
 */
class RecurringJournal extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'day_of_month' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'last_generated_on' => 'immutable_date',
            'is_active' => 'boolean',
            'lines' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Tanggal jurnal untuk bulan tertentu.
     *
     * Templat "tiap tanggal 31" tidak boleh melompati Februari; ia jatuh ke hari terakhir bulan itu.
     * Memilih diam saja pada bulan pendek berarti beban bulan itu hilang tanpa ada yang tahu.
     */
    public function dateFor(CarbonImmutable $month): CarbonImmutable
    {
        $akhir = (int) $month->endOfMonth()->format('j');

        return $month->startOfMonth()->addDays(min($this->day_of_month, $akhir) - 1);
    }

    public function isDue(CarbonImmutable $month): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $tanggal = $this->dateFor($month);

        return ! $tanggal->lessThan($this->starts_on)
            && ($this->ends_on === null || ! $tanggal->greaterThan($this->ends_on));
    }
}
