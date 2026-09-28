<?php

use App\Modules\Payment\Application\QrisPayload;
use Tests\Support\Qris;

/**
 * Pemeriksa payload QRIS.
 *
 * Ditulis setelah kejadian 27 Sep 2026: QR dari driver sandbox dipindai dengan m-banking dan
 * ditolak ("pastikan QR yang discan berlogo QRIS"). Gambarnya benar, isinya yang bukan QRIS.
 * Uji ini mengunci perbedaan itu supaya tidak lagi terbaca sebagai "QR-nya rusak".
 */
it('menerima payload QRIS sungguhan', function () {
    expect(QrisPayload::reason(Qris::CONTOH))->toBeNull()
        ->and(QrisPayload::isValid(Qris::CONTOH))->toBeTrue();
});

it('menghitung CRC EMVCo dengan benar', function () {
    // CRC-16/CCITT-FALSE atas seluruh payload kecuali 4 digit terakhir.
    expect(QrisPayload::crc16(substr(Qris::CONTOH, 0, -4)))->toBe('CCE3');
});

it('membaca tag wajib dari payload', function () {
    $tlv = QrisPayload::parse(Qris::CONTOH);

    expect($tlv['53'])->toBe('360')        // IDR
        ->and($tlv['58'])->toBe('ID')      // Indonesia
        ->and($tlv['59'])->toBe('PT AINO Indonesia');
});

it('menolak payload tiruan driver sandbox', function () {
    // Inilah yang dipindai user dan ditolak bank: panjang tag 26 berbunyi "SA", bukan angka.
    $tiruan = '00020101021226SANDBOXSBX-ABCDEFGH12345678JKLM530336054005720063 04SBOX';

    expect(QrisPayload::isValid($tiruan))->toBeFalse();
});

it('menyebutkan alasan penolakan yang bisa ditindaklanjuti', function (string $payload, string $potongan) {
    expect(QrisPayload::reason($payload))->toContain($potongan);
})->with([
    'tanpa tag CRC' => ['0002010102121234', 'tag CRC 6304'],
    'CRC salah' => [substr(Qris::CONTOH, 0, -4).'0000', 'CRC tidak cocok'],
    'kosong' => ['', 'kosong'],
]);

it('menolak payload yang CRC-nya benar tetapi bukan QRIS domestik', function () {
    // Dibangun sendiri supaya CRC-nya cocok: membuktikan pemeriksaannya tidak berhenti di CRC.
    $tanpaPenanda = '000201010212'.'5303360'.'5802ID'.'6304';
    $tanpaPenanda .= QrisPayload::crc16($tanpaPenanda);

    expect(QrisPayload::reason($tanpaPenanda))->toContain('ID.CO.QRIS.WWW');
});

it('menolak mata uang selain rupiah', function () {
    $dolar = '000201010212'.'0014ID.CO.QRIS.WWW'.'5303840'.'5802ID'.'6304';
    $dolar .= QrisPayload::crc16($dolar);

    expect(QrisPayload::reason($dolar))->toContain('bukan 360');
});
