<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Promo (FR-MENU-11, FR-MENU-12).
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $brand_id
 * @property string $name
 * @property string|null $code
 * @property string|null $code_hash
 * @property string $type
 * @property string $scope
 * @property string $value
 * @property string|null $min_purchase
 * @property string|null $max_discount
 * @property int|null $buy_qty
 * @property int|null $get_qty
 * @property list<string>|null $channel_codes
 * @property list<string>|null $payment_methods
 * @property list<int>|null $days_of_week
 * @property string|null $time_start
 * @property string|null $time_end
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $quota
 * @property int $used_count
 * @property bool $stackable
 * @property bool $auto_apply
 * @property int $priority
 * @property bool $is_active
 * @property-read Collection<int, PromotionTarget> $targets
 * @property-read Collection<int, Outlet> $outlets
 */
class Promotion extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    public const TYPES = ['percent', 'amount', 'buy_x_get_y', 'special_price'];

    public const SCOPES = ['order', 'items'];

    protected $fillable = [
        'brand_id', 'name', 'code', 'type', 'scope', 'value', 'min_purchase', 'max_discount', 'buy_qty', 'get_qty',
        'channel_codes', 'payment_methods', 'days_of_week', 'time_start', 'time_end', 'starts_at', 'ends_at',
        'quota', 'stackable', 'auto_apply', 'priority', 'is_active',
    ];

    protected $attributes = [
        'scope' => 'order',
        'value' => '0',
        'used_count' => 0,
        'stackable' => false,
        'auto_apply' => true,
        'priority' => 0,
        'is_active' => true,
    ];

    /** Iterasi PBKDF2 kode promo; POS memakai nilai yang tertera di hash. */
    public const CODE_HASH_ITERATIONS = 100000;

    protected static function booted(): void
    {
        // Dijalankan setelah HasUuids mengisi id (dipakai sebagai salt).
        static::creating(fn (Promotion $p) => $p->refreshCodeHash());
        static::updating(function (Promotion $p): void {
            if ($p->isDirty('code')) {
                $p->refreshCodeHash();
            }
        });
    }

    /**
     * Hash kode untuk dicocokkan offline oleh POS tanpa mengirim kode asli:
     * pbkdf2_sha256$<iterasi>$<hex(PBKDF2-SHA256(UPPER(TRIM(kode)), salt = id promo, 32 byte))>.
     */
    public static function hashCode(string $promotionId, string $code): string
    {
        $digest = hash_pbkdf2('sha256', mb_strtoupper(trim($code)), $promotionId, self::CODE_HASH_ITERATIONS, 64);

        return 'pbkdf2_sha256$'.self::CODE_HASH_ITERATIONS.'$'.$digest;
    }

    private function refreshCodeHash(): void
    {
        $code = $this->getAttribute('code');
        $this->setAttribute('code_hash', is_string($code) && $code !== '' ? self::hashCode((string) $this->getKey(), $code) : null);
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'buy_qty' => 'integer',
            'get_qty' => 'integer',
            'channel_codes' => 'array',
            'payment_methods' => 'array',
            'days_of_week' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'quota' => 'integer',
            'used_count' => 'integer',
            'stackable' => 'boolean',
            'auto_apply' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<PromotionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    /** @return BelongsToMany<Outlet, $this, PromotionOutlet, 'pivot'> */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'promotion_outlets')
            ->using(PromotionOutlet::class)
            ->withPivot(['id', 'company_id'])
            ->withTimestamps();
    }

    /**
     * Bentuk data yang dipakai PromotionEngine (juga dikirim ke POS untuk mode offline).
     *
     * @return array<string, mixed>
     */
    public function toEngineArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'brand_id' => $this->brand_id,
            'type' => $this->type,
            'scope' => $this->scope,
            'value' => (string) $this->value,
            'min_purchase' => $this->min_purchase === null ? null : (string) $this->min_purchase,
            'max_discount' => $this->max_discount === null ? null : (string) $this->max_discount,
            'buy_qty' => $this->buy_qty,
            'get_qty' => $this->get_qty,
            'item_ids' => $this->targets->where('target_type', 'item')->pluck('target_id')->values()->all(),
            'category_ids' => $this->targets->where('target_type', 'category')->pluck('target_id')->values()->all(),
            'outlet_ids' => $this->outlets->pluck('id')->values()->all(),
            'channel_codes' => $this->channel_codes,
            'payment_methods' => $this->payment_methods,
            'days_of_week' => $this->days_of_week,
            'time_start' => $this->time_start === null ? null : substr($this->time_start, 0, 5),
            'time_end' => $this->time_end === null ? null : substr($this->time_end, 0, 5),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'quota_remaining' => $this->quota === null ? null : max(0, $this->quota - $this->used_count),
            'stackable' => $this->stackable,
            'auto_apply' => $this->auto_apply,
            'priority' => $this->priority,
        ];
    }
}
