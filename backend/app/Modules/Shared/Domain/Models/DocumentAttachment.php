<?php

namespace App\Modules\Shared\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti yang menyertai sebuah dokumen (DOC-01): foto nota, bukti transfer, faktur, kontrak.
 *
 * Pemiliknya polimorfik tetapi **tidak memakai relasi morph Eloquent** — `owner_type` diisi kata
 * pendek ('journal', 'payment_request', 'payment_advice'), bukan nama kelas. Nama kelas yang
 * tersimpan di basis data membuat setiap pemindahan berkas PHP menjadi migrasi data; kata pendek
 * bertahan selama nama bisnisnya bertahan.
 *
 * Berkasnya tidak pernah disajikan publik: foto nota memuat nama pihak, nominal, kadang NPWP.
 *
 * @property string $id
 * @property string $company_id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $uploaded_by
 * @property CarbonImmutable $created_at
 */
class DocumentAttachment extends Model
{
    use BelongsToCompany;
    use HasUuids;

    public const JOURNAL = 'journal';

    public const PAYMENT_REQUEST = 'payment_request';

    public const PAYMENT_ADVICE = 'payment_advice';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
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
