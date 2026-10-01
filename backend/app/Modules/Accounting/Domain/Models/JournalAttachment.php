<?php

namespace App\Modules\Accounting\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti yang menyertai sebuah jurnal (ACC-05): foto nota, bukti transfer, kontrak.
 *
 * Berkasnya **tidak disajikan publik** seperti foto menu — foto nota memuat nama, nominal, dan
 * kadang NPWP. Pengunduhannya lewat rute berotentikasi yang memeriksa entitas dan izin.
 *
 * @property string $id
 * @property string $company_id
 * @property string $journal_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $uploaded_by
 * @property CarbonImmutable $created_at
 */
class JournalAttachment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Journal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sizeLabel(): string
    {
        $kb = $this->size_bytes / 1024;

        return $kb < 1024 ? number_format($kb, 0, ',', '.').' KB' : number_format($kb / 1024, 1, ',', '.').' MB';
    }
}
