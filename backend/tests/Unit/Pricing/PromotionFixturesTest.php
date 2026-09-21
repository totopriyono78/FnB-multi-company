<?php

use App\Modules\Catalog\Domain\Pricing\PromotionEngine;
use App\Modules\Catalog\Domain\Pricing\ScheduleMatcher;
use Carbon\CarbonImmutable;

function promotionFixtures(): array
{
    $files = glob(dirname(__DIR__, 4).'/shared/fixtures/promotions/*.json') ?: [];
    sort($files);
    $cases = [];
    foreach ($files as $file) {
        $fixture = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $cases[$fixture['name'].' — '.$fixture['description']] = [$fixture];
    }

    return $cases;
}

it('memiliki fixture promo bersama', function () {
    expect(count(promotionFixtures()))->toBeGreaterThanOrEqual(20);
});

it('menerapkan promo sesuai fixture bersama (BR-18)', function (array $fixture) {
    $in = $fixture['input'];
    $result = (new PromotionEngine)->apply($in['cart'], $in['context'], $in['promotions']);

    expect($result)->toBe($fixture['expected']);
})->with(promotionFixtures());

it('mencocokkan jadwal menu termasuk yang melewati tengah malam (FR-MENU-07)', function (?array $windows, string $at, bool $expected) {
    expect(ScheduleMatcher::matches($windows, CarbonImmutable::parse($at, 'Asia/Jakarta')))->toBe($expected);
})->with([
    'tanpa jadwal' => [null, '2026-09-16 03:00', true],
    'sarapan pagi' => [[['start' => '06:00', 'end' => '10:00']], '2026-09-16 07:00', true],
    'sarapan lewat jam' => [[['start' => '06:00', 'end' => '10:00']], '2026-09-16 10:00', false],
    'hari kerja saja (Sabtu)' => [[['days' => [1, 2, 3, 4, 5], 'start' => '06:00', 'end' => '10:00']], '2026-09-19 07:00', false],
    'malam Jumat lewat tengah malam' => [[['days' => [5], 'start' => '22:00', 'end' => '02:00']], '2026-09-19 01:30', true],
    'malam Jumat, Minggu dini hari' => [[['days' => [5], 'start' => '22:00', 'end' => '02:00']], '2026-09-20 01:30', false],
    'beberapa jendela' => [[['start' => '06:00', 'end' => '10:00'], ['start' => '14:00', 'end' => '16:00']], '2026-09-16 15:00', true],
]);
