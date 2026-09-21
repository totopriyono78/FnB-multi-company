<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Factory;

beforeEach(function () {
    [$this->a, $this->ownerA] = Factory::company('Kopi Tepi Jalan');
    [$this->b, $this->ownerB] = Factory::company('Warung Bu Ratna');
    $this->brandB = Factory::brand($this->b, ['code' => 'WBR', 'name' => 'Warung Bu Ratna']);
    $this->outletB = Factory::outlet($this->b, $this->brandB, ['code' => 'TLG']);
    $this->deviceB = Factory::device($this->b, $this->outletB);
    [, $this->memberB] = Factory::staff($this->b, ['cashier'], [$this->outletB->id]);
    $this->roleB = Factory::tenant($this->b, fn () => Role::query()->where('company_id', $this->b->id)->where('name', 'cashier')->firstOrFail());
});

it('menolak akses data company lain dengan 404 (SRS §10.1)', function (string $method, string $uri) {
    $uri = strtr($uri, [
        '{brand}' => $this->brandB->id,
        '{outlet}' => $this->outletB->id,
        '{device}' => $this->deviceB->id,
        '{member}' => $this->memberB->id,
        '{role}' => $this->roleB->id,
    ]);

    $response = $this->json($method, $uri, ['name' => 'Coba', 'reason' => 'uji', 'pin' => '4826', 'pin_confirmation' => '4826'], asMember($this->ownerA, $this->a));

    $response->assertNotFound();
    assertStandardEnvelope($response, false);
    expect(firstErrorCode($response))->toBe('NOT_FOUND');
})->with([
    ['GET', '/api/v1/brands/{brand}'],
    ['PATCH', '/api/v1/brands/{brand}'],
    ['DELETE', '/api/v1/brands/{brand}'],
    ['GET', '/api/v1/outlets/{outlet}'],
    ['PATCH', '/api/v1/outlets/{outlet}'],
    ['DELETE', '/api/v1/outlets/{outlet}'],
    ['GET', '/api/v1/devices/{device}'],
    ['PATCH', '/api/v1/devices/{device}'],
    ['POST', '/api/v1/devices/{device}/pairing-code'],
    ['POST', '/api/v1/devices/{device}/revoke'],
    ['GET', '/api/v1/staff/{member}'],
    ['PATCH', '/api/v1/staff/{member}'],
    ['PUT', '/api/v1/staff/{member}/pin'],
    ['POST', '/api/v1/staff/{member}/revoke-sessions'],
    ['GET', '/api/v1/roles/{role}'],
    ['PATCH', '/api/v1/roles/{role}'],
    ['DELETE', '/api/v1/roles/{role}'],
]);

it('mencatat percobaan akses lintas tenant di audit log', function () {
    $this->getJson('/api/v1/brands/'.$this->brandB->id, asMember($this->ownerA, $this->a))->assertNotFound();

    $log = Factory::tenant($this->a, fn () => AuditLog::query()->where('action', 'security.cross_tenant_access')->first());

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->ownerA->id)
        ->and($log->metadata['entity_id'])->toBe($this->brandB->id)
        ->and($log->metadata)->not->toHaveKey('owner_company_id');

    // Detail lengkap hanya ada di log platform (tanpa company).
    $platform = Factory::system(fn () => DB::table('audit_logs')->whereNull('company_id')->where('action', 'security.cross_tenant_access')->first());
    expect(json_decode($platform->metadata, true))->toMatchArray([
        'owner_company_id' => $this->b->id,
        'actor_company_id' => $this->a->id,
    ]);

    // Company B tidak melihat log milik company A.
    expect(Factory::tenant($this->b, fn () => AuditLog::query()->where('action', 'security.cross_tenant_access')->count()))->toBe(0);
});

it('hanya menampilkan data company aktif pada daftar', function () {
    Factory::brand($this->a, ['code' => 'KTJ', 'name' => 'Kopi Tepi Jalan']);

    $response = $this->getJson('/api/v1/brands', asMember($this->ownerA, $this->a))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.code'))->toBe('KTJ')
        ->and($response->json('meta.pagination.total'))->toBe(1);
});

it('menolak header company yang bukan keanggotaan user', function () {
    $response = $this->getJson('/api/v1/brands', asMember($this->ownerA, $this->b));

    $response->assertForbidden();
    expect(firstErrorCode($response))->toBe('NOT_A_MEMBER');
});

it('mewajibkan pemilihan company untuk endpoint tenant', function () {
    $response = $this->getJson('/api/v1/brands', asMember($this->ownerA));

    $response->assertStatus(400);
    expect(firstErrorCode($response))->toBe('COMPANY_REQUIRED');

    $this->getJson('/api/v1/brands', asMember($this->ownerA) + ['X-Company-Id' => 'bukan-uuid'])->assertStatus(400);
});

it('menolak token device yang dipakai dengan header company lain', function () {
    [, $token] = Factory::pairedDevice($this->b, $this->outletB);

    $response = $this->getJson('/api/v1/pos/staff', bearer($token) + ['X-Company-Id' => $this->a->id]);

    $response->assertForbidden();
    expect(firstErrorCode($response))->toBe('COMPANY_MISMATCH');
});

it('menolak id yang bukan UUID tanpa error server', function () {
    $this->getJson('/api/v1/brands/123', asMember($this->ownerA, $this->a))->assertNotFound();
    $this->getJson('/api/v1/roles/abc', asMember($this->ownerA, $this->a))->assertNotFound();
});

describe('Row-Level Security PostgreSQL', function () {
    it('menyembunyikan data company lain walau global scope dimatikan', function () {
        $rows = Factory::tenant($this->a, fn () => Brand::query()->withoutGlobalScopes()->count());
        $raw = Factory::tenant($this->a, fn () => DB::table('outlets')->count());

        expect($rows)->toBe(0)->and($raw)->toBe(0);
    });

    it('tidak menampilkan data apa pun tanpa konteks tenant', function () {
        $context = app(TenantContext::class);
        $context->restrict();

        try {
            expect(DB::table('brands')->count())->toBe(0)
                ->and(DB::table('companies')->count())->toBe(0)
                ->and(Outlet::query()->count())->toBe(0);
        } finally {
            $context->reset();
        }
    });

    it('menolak insert dengan company_id milik tenant lain', function () {
        Factory::tenant($this->a, fn () => DB::transaction(function () {
            DB::table('brands')->insert([
                'id' => (string) Str::uuid7(),
                'company_id' => $this->b->id,
                'code' => 'ILEGAL',
                'name' => 'Sisipan',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }));
    })->throws(QueryException::class, 'row-level security');

    it('menolak pemindahan data ke company lain lewat model', function () {
        $brand = Factory::brand($this->a);

        Factory::tenant($this->a, function () use ($brand) {
            $brand->forceFill(['company_id' => $this->b->id])->save();
        });
    })->throws(LogicException::class);

    it('mengaktifkan RLS pada semua tabel ber-company_id', function () {
        $tables = collect(DB::select(<<<'SQL'
            SELECT c.relname, c.relrowsecurity
            FROM pg_class c
            JOIN information_schema.columns col ON col.table_name = c.relname AND col.column_name = 'company_id'
            WHERE c.relkind IN ('r', 'p') AND col.table_schema = 'public' AND c.relispartition = false
        SQL));

        $withoutRls = $tables->where('relrowsecurity', false)->pluck('relname')->reject(fn ($t) => $t === 'personal_access_tokens');

        expect($tables->count())->toBeGreaterThan(5)
            ->and($withoutRls->all())->toBe([]);
    });
});
