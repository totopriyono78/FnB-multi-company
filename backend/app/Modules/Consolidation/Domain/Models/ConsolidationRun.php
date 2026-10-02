<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu proses konsolidasi: satu grup, satu periode (CON-01).
 *
 * Selama `draft` ia boleh dijalankan ulang kapan saja — dan itu penting, bukan kemudahan. Angka anak
 * usaha masih berubah sampai tutup buku, dan modul yang belum ada (penyusutan aset, rekap gaji) akan
 * mengubahnya lagi nanti. Konsolidasi yang hanya bisa dihitung sekali akan basi pada hari kedua.
 *
 * `final` menguncinya. Membukanya kembali boleh, tetapi tercatat di jejak audit: angka grup yang
 * sudah dilaporkan ke pemilik lalu berubah tanpa jejak adalah persoalan yang jauh lebih besar
 * daripada angka yang salah.
 *
 * @property string $id
 * @property string $company_id
 * @property string $group_id
 * @property string $number
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $status
 * @property string|null $label
 * @property CarbonImmutable|null $generated_at
 * @property string|null $generated_by
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $finalized_by
 * @property int $entity_count
 * @property string|null $notes
 * @property-read Group|null $group
 */
class ConsolidationRun extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;

    public const DRAFT = 'draft';

    public const FINAL = 'final';

    public const STATUS_LABEL = [
        self::DRAFT => 'Draft',
        self::FINAL => 'Final',
    ];

    public const PREFIX = 'KON';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'generated_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'entity_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return HasMany<ConsolidationEntity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(ConsolidationEntity::class, 'run_id')->orderBy('sequence');
    }

    /** @return HasMany<ConsolidationBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(ConsolidationBalance::class, 'run_id');
    }

    /** @return HasMany<ConsolidationAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(ConsolidationAdjustment::class, 'run_id')->orderBy('sequence');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function periodLabel(): string
    {
        return $this->period_start->translatedFormat('d M Y').' – '.$this->period_end->translatedFormat('d M Y');
    }

    /** Dinamai `describe()`, bukan `label()`, karena `label` sudah menjadi nama kolomnya. */
    public function describe(): string
    {
        return $this->number.' · '.($this->label ?? $this->periodLabel());
    }
}
