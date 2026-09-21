<?php

namespace App\Modules\Purchasing\Domain\Models;

use App\Modules\Audit\Application\Auditable;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Domain\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pemasok bahan (FR-PUR).
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property int $payment_term_days
 * @property string|null $notes
 * @property bool $is_active
 */
class Supplier extends Model
{
    use Auditable;
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;
    use TracksAuthor;

    protected $fillable = ['code', 'name', 'contact_name', 'phone', 'email', 'address', 'payment_term_days', 'notes', 'is_active'];

    protected $attributes = ['payment_term_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['payment_term_days' => 'integer', 'is_active' => 'boolean'];
    }
}
