<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Akun dalam bagan akun (ACC-01). Milik satu company.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_balance
 * @property string|null $parent_id
 * @property bool $is_postable
 * @property bool $is_active
 * @property bool $is_system
 * @property string|null $description
 */
class Account extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const ASSET = 'asset';

    public const LIABILITY = 'liability';

    public const EQUITY = 'equity';

    public const REVENUE = 'revenue';

    public const COGS = 'cogs';

    public const EXPENSE = 'expense';

    /** Saldo normal tiap jenis akun. Inilah yang menentukan arah penambahan saldo di buku besar. */
    public const NORMAL = [
        self::ASSET => 'debit',
        self::COGS => 'debit',
        self::EXPENSE => 'debit',
        self::LIABILITY => 'credit',
        self::EQUITY => 'credit',
        self::REVENUE => 'credit',
    ];

    public const TYPE_LABEL = [
        self::ASSET => 'Aset',
        self::LIABILITY => 'Liabilitas',
        self::EQUITY => 'Ekuitas',
        self::REVENUE => 'Pendapatan',
        self::COGS => 'Harga Pokok Penjualan',
        self::EXPENSE => 'Beban',
    ];

    /** Akun neraca (saldonya berlanjut antar periode) vs akun laba rugi (ditutup tiap periode). */
    public const BALANCE_SHEET = [self::ASSET, self::LIABILITY, self::EQUITY];

    protected $fillable = ['code', 'name', 'type', 'parent_id', 'is_postable', 'is_active', 'description'];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isBalanceSheet(): bool
    {
        return in_array($this->type, self::BALANCE_SHEET, true);
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }
}
