<?php

namespace App\Modules\Sales\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabel berpartisi (PK = id + business_date): sertakan business_date saat UPDATE agar hanya satu partisi disentuh.
 *
 * @mixin Model
 */
trait PartitionedByBusinessDate
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());
        $query->where('business_date', $this->getOriginal('business_date') ?? $this->getAttribute('business_date'));

        return $query;
    }
}
