<?php

namespace App\Modules\Consolidation\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu entitas anggota di dalam satu proses konsolidasi, beserta keadaan bukunya saat ditarik.
 *
 * Kode dan nama entitas **disalin** ke sini. Itu bukan denormalisasi yang malas: entitas holding
 * tidak boleh — dan tidak bisa — membaca baris `companies` anak usaha, jadi tanpa salinan ini kertas
 * kerja tidak punya judul kolom. Salinan juga membuat laporan periode lama tetap menyebut nama yang
 * berlaku saat itu, bahkan setelah entitasnya berganti nama.
 *
 * Tiga kolom terakhir adalah alasan utama tabel ini ada: ia menjawab "kenapa angka entitas ini
 * nol?" dan "kenapa neraca konsolidasinya timpang?" di tempat yang bisa dibaca, bukan membiarkan
 * keduanya muncul sebagai laporan yang aneh tanpa petunjuk entitas penyebabnya.
 *
 * @property string $id
 * @property string $company_id
 * @property string $run_id
 * @property string $source_company_id
 * @property string $source_code
 * @property string $source_name
 * @property int $sequence
 * @property int $posted_journal_count
 * @property int $draft_journal_count
 * @property CarbonImmutable|null $last_journal_date
 * @property bool $out_of_balance
 * @property string $imbalance
 */
class ConsolidationEntity extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'posted_journal_count' => 'integer',
            'draft_journal_count' => 'integer',
            'last_journal_date' => 'immutable_date',
            'out_of_balance' => 'boolean',
        ];
    }

    /** @return BelongsTo<ConsolidationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ConsolidationRun::class, 'run_id');
    }

    public function hasData(): bool
    {
        return $this->posted_journal_count > 0;
    }
}
