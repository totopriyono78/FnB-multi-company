<?php

namespace App\Modules\Payment\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kredensial merchant di payment gateway, bercakupan outlet (NULL = seluruh company).
 *
 * `secret_key` memakai cast `encrypted`: tersimpan sebagai ciphertext APP_KEY di basis data,
 * jadi dump basis data atau backup yang bocor tidak langsung membocorkan kunci merchant.
 * Nilainya tidak pernah dikirim ke layar mana pun — lihat PaymentGatewayAccountResource.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $outlet_id
 * @property string $provider
 * @property string $environment
 * @property string $merchant_code
 * @property string $secret_key
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Outlet|null $outlet
 */
class PaymentGatewayAccount extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    /*
     * Kolom yang boleh diisi massal dari form back-office. `company_id` sengaja TIDAK ada di sini:
     * nilainya diisi otomatis oleh trait BelongsToCompany dari konteks tenant, dan membiarkannya
     * bisa diisi dari form berarti kredensial bisa dititipkan ke company lain.
     *
     * Model lain di modul ini memakai `$guarded = ['*']` karena hanya ditulis lewat forceFill di
     * kode; yang ini punya layar isian, jadi butuh daftar fillable yang sungguhan.
     */
    protected $fillable = ['outlet_id', 'provider', 'environment', 'merchant_code', 'secret_key', 'is_active'];

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
