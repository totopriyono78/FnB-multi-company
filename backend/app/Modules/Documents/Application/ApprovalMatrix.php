<?php

namespace App\Modules\Documents\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use App\Modules\Identity\Application\PermissionRegistry;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Batas wewenang persetujuan (DOC-09).
 *
 * Bentuknya **band nilai × tingkat**: satu pengajuan masuk ke band menurut nilainya, dan band itu
 * menyebut berapa tanda tangan yang dibutuhkan serta peran apa di tiap tingkat.
 *
 *     sampai   5.000.000 → tingkat 1: finance
 *     sampai  50.000.000 → tingkat 1: finance, tingkat 2: company_admin
 *     tanpa batas        → tingkat 1: finance, tingkat 2: company_admin, tingkat 3: owner
 *
 * Tiga hal yang membuatnya begini dan bukan lebih sederhana:
 *
 * 1. **Ini data, bukan kode.** Kebijakan tanda tangan adalah hal yang paling sering berubah di grup
 *    usaha — pergantian direktur, pemekaran unit, pengetatan setelah temuan audit. Perubahan
 *    seperti itu tidak boleh menunggu rilis.
 * 2. **Band dipilih dari batas atas TERKECIL yang masih memuat nilainya**, bukan dari yang pertama
 *    cocok. Dengan begitu urutan baris di basis data tidak pernah menentukan hasil, dan dua orang
 *    yang membaca matriks yang sama selalu sampai pada kesimpulan yang sama.
 * 3. **Kewenangan diperiksa dari PERAN, bukan izin.** Izin menjawab "boleh membuka layar apa";
 *    tanda tangan menjawab "siapa yang bertanggung jawab". Memakai izin untuk keduanya membuat
 *    orang yang diberi akses baca ikut bisa menandatangani tanpa ada yang menyadarinya.
 */
class ApprovalMatrix
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Matriks bawaan yang masuk akal untuk grup usaha kecil-menengah. Idempoten.
     *
     * @return int jumlah baris yang dibuat
     */
    public function installDefaults(string $docType = ApprovalRule::PAYMENT_REQUEST): int
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        if (ApprovalRule::query()->where('doc_type', $docType)->exists()) {
            return 0;
        }

        $bawaan = [
            ['5000000', 1, 'finance'],
            ['50000000', 1, 'finance'],
            ['50000000', 2, 'company_admin'],
            [null, 1, 'finance'],
            [null, 2, 'company_admin'],
            [null, 3, 'owner'],
        ];

        return DB::transaction(function () use ($companyId, $docType, $bawaan): int {
            foreach ($bawaan as [$max, $level, $role]) {
                $this->write($companyId, $docType, $max, (int) $level, (string) $role);
            }
            $this->audit->log('approval_matrix.defaults_installed', null,
                new: ['doc_type' => $docType, 'rules' => count($bawaan)]);

            return count($bawaan);
        });
    }

    /**
     * Tanda tangan yang dibutuhkan untuk nilai tersebut, berurut dari tingkat 1.
     *
     * @return list<array{level: int, role: string}>
     */
    public function requiredFor(string $docType, BigDecimal $amount): array
    {
        /*
         * Band dipilih lewat satu kueri: ambil seluruh baris yang batasnya masih memuat nilai ini,
         * urutkan dari batas terkecil (tanpa batas paling akhir), lalu pakai batas yang muncul
         * pertama. NULLS LAST yang membuat band "tanpa batas" hanya terpakai bila tidak ada band
         * berbatas yang cukup.
         */
        $rows = DB::table('approval_rules')
            ->where('doc_type', $docType)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', (string) $amount))
            ->orderByRaw('max_amount ASC NULLS LAST')
            ->orderBy('level')
            ->get(['max_amount', 'level', 'role']);

        if ($rows->isEmpty()) {
            $adaAturan = ApprovalRule::query()->where('doc_type', $docType)->exists();

            throw new DocumentException('APPROVAL_MATRIX_MISSING',
                $adaAturan
                    ? 'Nilai ini melebihi band tertinggi yang diatur di matriks batas wewenang. '
                        .'Tambahkan band tanpa batas, atau naikkan batas band teratas.'
                    : 'Matriks batas wewenang belum diatur. Buka Dokumen → Batas Wewenang dan pasang matriks bawaan lebih dulu.',
                422, field: 'amount');
        }

        $band = $rows->first()->max_amount;

        return $rows
            ->filter(fn ($r) => $r->max_amount === $band)
            ->map(fn ($r) => ['level' => (int) $r->level, 'role' => (string) $r->role])
            ->values()
            ->all();
    }

    /** Peran yang harus menandatangani tingkat tertentu untuk nilai tersebut. */
    public function roleForLevel(string $docType, BigDecimal $amount, int $level): string
    {
        foreach ($this->requiredFor($docType, $amount) as $aturan) {
            if ($aturan['level'] === $level) {
                return $aturan['role'];
            }
        }

        throw new DocumentException('APPROVAL_LEVEL_UNKNOWN',
            "Tingkat persetujuan ke-{$level} tidak ada di matriks untuk nilai ini.", 422, field: 'level');
    }

    /** Apakah orang ini memegang peran yang dituntut tingkat itu di entitas aktif? */
    public function holdsRole(User $user, string $role): bool
    {
        return $user->hasRole($role);
    }

    /** @return array<string, string> peran yang boleh dipakai di matriks */
    public static function roleOptions(): array
    {
        $out = [];
        foreach (PermissionRegistry::defaultRoles() as $name => $def) {
            $out[$name] = $def['label'];
        }

        return $out;
    }

    public function set(string $docType, ?string $maxAmount, int $level, string $role): ApprovalRule
    {
        $this->assertRole($role);

        return $this->write(app(TenantContext::class)->requireCompanyId(), $docType, $maxAmount, $level, $role);
    }

    /**
     * Pindahkan satu baris yang sudah ada ke band/tingkat/peran lain.
     *
     * Ini bukan hal yang sama dengan `set()`. `set()` mengenali baris dari band + tingkatnya, jadi
     * mengubah tingkat sebuah baris lewat `set()` akan membuat baris baru dan **meninggalkan baris
     * lamanya** — matriks yang menuntut tanda tangan hantu di tingkat yang sudah dipindahkan.
     */
    public function move(ApprovalRule $row, string $docType, ?string $maxAmount, int $level, string $role): ApprovalRule
    {
        $this->assertRole($role);

        return DB::transaction(function () use ($row, $docType, $maxAmount, $level, $role): ApprovalRule {
            $bentrok = ApprovalRule::query()->whereKeyNot($row->id)
                ->where('doc_type', $docType)->where('level', $level)
                ->when($maxAmount === null,
                    fn ($q) => $q->whereNull('max_amount'),
                    fn ($q) => $q->where('max_amount', $maxAmount))
                ->exists();
            if ($bentrok) {
                throw new DocumentException('APPROVAL_RULE_DUPLICATE',
                    'Sudah ada baris lain untuk band dan tingkat itu. Ubah baris tersebut, atau hapus salah satunya.',
                    422, field: 'level');
            }

            $lama = ['max_amount' => $row->max_amount, 'level' => $row->level, 'role' => $row->role];
            $row->forceFill(['doc_type' => $docType, 'max_amount' => $maxAmount, 'level' => $level, 'role' => $role])->save();
            $this->audit->log('approval_matrix.updated', $row, old: $lama,
                new: ['max_amount' => $maxAmount, 'level' => $level, 'role' => $role]);

            return $row->refresh();
        });
    }

    private function assertRole(string $role): void
    {
        if (! array_key_exists($role, self::roleOptions())) {
            throw new DocumentException('APPROVAL_ROLE_UNKNOWN', "Peran {$role} tidak dikenal.", 422, field: 'role');
        }
    }

    private function write(string $companyId, string $docType, ?string $maxAmount, int $level, string $role): ApprovalRule
    {
        /** @var ApprovalRule|null $row */
        $row = ApprovalRule::query()->where('doc_type', $docType)->where('level', $level)
            ->when($maxAmount === null,
                fn ($q) => $q->whereNull('max_amount'),
                fn ($q) => $q->where('max_amount', $maxAmount))
            ->first();

        if ($row === null) {
            $row = new ApprovalRule;
            $row->forceFill([
                'id' => (string) Str::uuid7(), 'company_id' => $companyId, 'doc_type' => $docType,
                'max_amount' => $maxAmount, 'level' => $level,
            ]);
        }
        $row->forceFill(['role' => $role])->save();

        return $row;
    }
}
