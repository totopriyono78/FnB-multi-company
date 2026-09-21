<?php

namespace App\Modules\Catalog\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $company_id
 * @property string $promotion_id
 * @property string $target_type
 * @property string $target_id
 */
class PromotionTarget extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = ['promotion_id', 'target_type', 'target_id'];
}
