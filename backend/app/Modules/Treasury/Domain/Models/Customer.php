<?php

namespace App\Modules\Treasury\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pelanggan yang ditagih (AR-01): katering korporat, penyewa tempat, mitra acara.
 *
 * Bukan tamu kasir. Tamu kasir membayar di tempat dan tidak perlu punya nama di sistem; yang perlu
 * punya nama adalah pihak yang tagihannya dibayar belakangan — karena hanya pihak itu yang bisa
 * menunggak.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $npwp
 * @property int $payment_term_days
 * @property bool $is_active
 * @property string|null $notes
 */
class Customer extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'code', 'name', 'contact_name', 'phone', 'email', 'address', 'npwp',
        'payment_term_days', 'is_active', 'notes',
    ];

    protected $attributes = ['payment_term_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['payment_term_days' => 'integer', 'is_active' => 'boolean'];
    }
}
