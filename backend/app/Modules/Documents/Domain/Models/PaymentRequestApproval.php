<?php

namespace App\Modules\Documents\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tanda tangan pada sebuah SPPK (DOC-04, DOC-10).
 *
 * Disimpan sebagai baris tersendiri, bukan kolom pada SPPK: persetujuan berjenjang berarti beberapa
 * orang, pada waktu berbeda, masing-masing dengan catatannya. Satu kolom hanya menyisakan yang
 * terakhir — dan justru tanda tangan pertamalah yang biasanya dicari saat ada yang dipertanyakan.
 *
 * @property string $id
 * @property string $company_id
 * @property string $payment_request_id
 * @property int $level
 * @property string $role
 * @property string $approved_by
 * @property CarbonImmutable $approved_at
 * @property string|null $note
 */
class PaymentRequestApproval extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'approved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
