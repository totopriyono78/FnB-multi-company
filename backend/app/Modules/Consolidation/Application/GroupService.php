<?php

namespace App\Modules\Consolidation\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Consolidation\Domain\Models\Group;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grup/holding dan keanggotaannya (GRP-01).
 *
 * ## Satu-satunya tempat yang menyentuh company lain
 *
 * Mengubah keanggotaan berarti menulis ke baris `companies` milik entitas lain, dan itu mustahil
 * dilakukan dari konteks tenant holding — RLS yang menolaknya. Jadi dua operasi di kelas ini
 * (`attach`, `detach`) adalah satu-satunya tempat di seluruh Kelompok 8 yang memakai `runAsSystem`
 * untuk MENULIS. Keduanya dijaga tiga hal sekaligus:
 *
 * 1. pemanggilnya wajib sudah berada dalam konteks entitas holding grup itu;
 * 2. entitas yang ditarik tidak boleh sudah menjadi anggota grup lain — entitas yang diklaim dua
 *    grup akan menghasilkan dua angka grup dari buku yang sama, dan tidak ada cara memilih mana
 *    yang benar;
 * 3. entitas holding sendiri tidak boleh dikeluarkan dari grupnya.
 *
 * Yang **tidak** dilakukan kelas ini: menyediakan daftar company untuk dipilih. Entitas holding
 * tidak boleh membaca `companies` lintas tenant, jadi keanggotaan ditentukan dengan menyebut kode
 * entitas — lewat perintah konsol `konsolidasi:grup` — bukan dengan memilih dari dropdown yang
 * isinya saja sudah merupakan kebocoran.
 */
class GroupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{code: string, name: string, legal_name?: string|null, currency?: string|null,
     *               notes?: string|null}  $data
     */
    public function create(array $data): Group
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        $code = $this->code($data['code'] ?? '');

        return DB::transaction(function () use ($companyId, $data, $code): Group {
            if (Group::query()->exists()) {
                throw new ConsolidationException('GROUP_EXISTS',
                    'Entitas ini sudah memegang satu grup. Ubah grup yang ada alih-alih membuat yang kedua.');
            }
            $taken = app(TenantContext::class)->runAsSystem(
                fn (): bool => Group::query()->withoutGlobalScopes()->where('code', $code)->exists()
            );
            if ($taken) {
                throw new ConsolidationException('GROUP_CODE_TAKEN', 'Kode grup sudah dipakai.', field: 'code');
            }

            $group = new Group;
            $group->forceFill([
                'id' => (string) Str::uuid7(),
                'company_id' => $companyId,
                'code' => $code,
                'name' => $this->text($data['name'] ?? '', 120, 'name'),
                'legal_name' => $this->optional($data['legal_name'] ?? null, 150),
                'currency' => strtoupper($this->optional($data['currency'] ?? null, 3) ?? 'IDR'),
                'notes' => $this->optional($data['notes'] ?? null, 300),
                'settings' => [],
                'created_by' => auth()->id(),
            ])->save();

            // Entitas holding otomatis menjadi anggota grupnya sendiri: ia punya buku sendiri
            // (talangan, beban kantor pusat) yang wajib ikut dikonsolidasi.
            Company::query()->whereKey($companyId)->update(['group_id' => $group->id]);

            $this->audit->log('group.created', $group, new: ['code' => $group->code, 'name' => $group->name]);

            return $group;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Group $group, array $data): Group
    {
        return DB::transaction(function () use ($group, $data): Group {
            $isi = [];
            foreach (['name' => 120, 'legal_name' => 150, 'notes' => 300] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $isi[$field] = $field === 'name'
                        ? $this->text($data[$field], $max, $field)
                        : $this->optional($data[$field], $max);
                }
            }
            if ($isi === []) {
                return $group;
            }

            $old = $group->only(array_keys($isi));
            $group->forceFill($isi)->save();
            $this->audit->log('group.updated', $group, old: $old, new: $isi);

            return $group->refresh();
        });
    }

    /**
     * Tarik satu entitas ke dalam grup, disebut dengan kode entitasnya.
     *
     * Kodenya, bukan id-nya: id entitas lain tidak pernah terlihat dari konteks holding, jadi
     * meminta id berarti meminta sesuatu yang hanya bisa didapat dari luar sistem.
     */
    public function attach(Group $group, string $companyCode): string
    {
        $this->assertOwns($group);
        $code = trim($companyCode);

        return DB::transaction(function () use ($group, $code): string {
            /** @var array{id: string, name: string, group_id: string|null}|null $target */
            $target = app(TenantContext::class)->runAsSystem(function () use ($code): ?array {
                $row = Company::query()->withoutGlobalScopes()
                    ->whereRaw('upper(code) = upper(?)', [$code])
                    ->first(['id', 'name', 'group_id']);

                return $row === null ? null : [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'group_id' => $row->group_id === null ? null : (string) $row->group_id,
                ];
            });

            if ($target === null) {
                throw new ConsolidationException('COMPANY_NOT_FOUND',
                    "Entitas dengan kode {$code} tidak ditemukan.", field: 'company_code');
            }
            if ($target['group_id'] === $group->id) {
                throw new ConsolidationException('ALREADY_MEMBER',
                    "{$target['name']} sudah menjadi anggota grup ini.", field: 'company_code');
            }
            if ($target['group_id'] !== null) {
                throw new ConsolidationException('MEMBER_OF_OTHER_GROUP',
                    "{$target['name']} sudah menjadi anggota grup lain. Keluarkan dari grup itu lebih dulu.",
                    field: 'company_code');
            }

            app(TenantContext::class)->runAsSystem(
                fn () => Company::query()->withoutGlobalScopes()
                    ->whereKey($target['id'])->update(['group_id' => $group->id])
            );

            $this->audit->log('group.member_attached', $group, new: [
                'company_code' => $code, 'company_name' => $target['name'],
            ]);

            return $target['name'];
        });
    }

    /** Keluarkan satu entitas dari grup. Entitas holding sendiri tidak bisa dikeluarkan. */
    public function detach(Group $group, string $companyCode): string
    {
        $this->assertOwns($group);
        $code = trim($companyCode);

        return DB::transaction(function () use ($group, $code): string {
            /** @var array{id: string, name: string}|null $target */
            $target = app(TenantContext::class)->runAsSystem(function () use ($code, $group): ?array {
                $row = Company::query()->withoutGlobalScopes()
                    ->whereRaw('upper(code) = upper(?)', [$code])
                    ->where('group_id', $group->id)->first(['id', 'name']);

                return $row === null ? null : ['id' => (string) $row->id, 'name' => (string) $row->name];
            });

            if ($target === null) {
                throw new ConsolidationException('NOT_A_MEMBER',
                    "Entitas dengan kode {$code} bukan anggota grup ini.", field: 'company_code');
            }
            if ($target['id'] === $group->company_id) {
                throw new ConsolidationException('HOLDING_CANNOT_LEAVE',
                    'Entitas holding tidak bisa dikeluarkan dari grupnya sendiri.', field: 'company_code');
            }

            app(TenantContext::class)->runAsSystem(
                fn () => Company::query()->withoutGlobalScopes()
                    ->whereKey($target['id'])->update(['group_id' => null])
            );

            $this->audit->log('group.member_detached', $group, old: [
                'company_code' => $code, 'company_name' => $target['name'],
            ]);

            return $target['name'];
        });
    }

    /**
     * Anggota grup: id, kode, dan nama tiap entitas, urut kode. Dibaca `runAsSystem` karena baris
     * company anak usaha tidak terlihat dari konteks holding.
     *
     * @return list<array{id: string, code: string, name: string}>
     */
    public function members(Group $group): array
    {
        return app(TenantContext::class)->runAsSystem(
            fn (): array => Company::query()->withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Company $c): array => [
                    'id' => (string) $c->id,
                    'code' => (string) $c->code,
                    'name' => (string) $c->name,
                ])->all()
        );
    }

    /** Grup milik entitas yang sedang aktif, bila ia memang entitas holding. */
    public function currentGroup(): ?Group
    {
        return Group::query()->orderBy('code')->first();
    }

    private function assertOwns(Group $group): void
    {
        $companyId = app(TenantContext::class)->requireCompanyId();
        if ($group->company_id !== $companyId) {
            throw new ConsolidationException('NOT_HOLDING',
                'Keanggotaan grup hanya bisa diubah dari entitas holding grup itu.', status: 403);
        }
    }

    private function code(string $value): string
    {
        $code = strtoupper(trim($value));
        if ($code === '' || ! preg_match('/^[A-Z0-9][A-Z0-9\-_]{1,39}$/', $code)) {
            throw new ConsolidationException('GROUP_CODE_INVALID',
                'Kode grup wajib diisi, 2–40 karakter, huruf/angka/tanda hubung.', field: 'code');
        }

        return $code;
    }

    private function text(mixed $value, int $max, string $field): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            throw new ConsolidationException('FIELD_REQUIRED', 'Kolom wajib diisi.', field: $field);
        }

        return mb_substr($text, 0, $max);
    }

    private function optional(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
