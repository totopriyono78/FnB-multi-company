<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grup/holding: lapisan di atas Company (GRP-01).
 *
 * `company_id` di sini adalah **entitas holding** yang memiliki baris grup ini — bukan anggota
 * sembarang. Dengan begitu grup memakai lapisan isolasi yang sama dengan tabel lain, dan anak usaha
 * tidak melihat keberadaan grupnya sama sekali.
 *
 * Keanggotaan disimpan di sisi sebaliknya, pada `companies.group_id`. Jadi satu company hanya bisa
 * menjadi anggota satu grup — yang memang satu-satunya bentuk yang punya arti pada konsolidasi
 * manajerial: dua grup yang sama-sama mengklaim satu entitas akan menghasilkan dua angka grup yang
 * berbeda dari entitas yang sama.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string $currency
 * @property string|null $notes
 * @property array<string, mixed> $settings
 * @property-read Company|null $company
 */
class Group extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /**
     * Entitas holding pemilik grup. Nama yang lebih jujur untuk relasi `company()`, dipakai di
     * tempat-tempat yang membacanya sebagai "holding" alih-alih "tenant".
     *
     * @return BelongsTo<Company, $this>
     */
    public function holdingCompany(): BelongsTo
    {
        return $this->company();
    }

    /** @return HasMany<ConsolidationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ConsolidationRun::class);
    }

    /** @return HasMany<ConsolidationMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(ConsolidationMapping::class);
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }
}
