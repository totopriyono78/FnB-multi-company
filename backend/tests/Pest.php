<?php

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Factory;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/** Header untuk memanggil API back-office sebagai user di company tertentu. */
function asMember(User $user, ?Company $company = null): array
{
    $headers = ['Authorization' => 'Bearer '.Factory::token($user), 'Accept' => 'application/json'];
    if ($company !== null) {
        $headers['X-Company-Id'] = $company->id;
    }

    return $headers;
}

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
}

/** Struktur respons standar SRS §7.4. */
function assertStandardEnvelope(TestResponse $response, bool $success = true): void
{
    $response->assertJsonStructure(['success', 'data', 'meta' => ['request_id'], 'errors']);
    expect($response->json('success'))->toBe($success);
}

function firstErrorCode(TestResponse $response): ?string
{
    return $response->json('errors.0.code');
}

/** @return list<string> daftar field yang gagal validasi */
function errorFields(TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}
