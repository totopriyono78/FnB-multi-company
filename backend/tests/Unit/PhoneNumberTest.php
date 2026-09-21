<?php

use App\Modules\Identity\Application\PhoneNumber;

it('menormalkan nomor HP Indonesia', function (?string $input, ?string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['81234567890', '6281234567890'],
    ['6281234567890', '6281234567890'],
    ['12345', null],
    [null, null],
]);
