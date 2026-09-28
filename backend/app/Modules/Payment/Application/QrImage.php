<?php

namespace App\Modules\Payment\Application;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Mengubah payload QRIS menjadi SVG.
 *
 * Dirender di server, bukan di peramban, dan dikirim sebagai markup inline — bukan tautan gambar.
 * Dua alasan: layar kasir harus tetap bisa menampilkan QR walau jaringan outlet sedang buruk
 * (tidak ada permintaan kedua yang bisa gagal), dan permintaan gambar terpisah akan menuntut
 * token perangkat ikut di URL, yang tidak seharusnya.
 *
 * Tingkat koreksi kesalahan M mengikuti kebiasaan QRIS: cukup tahan terhadap layar kotor atau
 * pantulan cahaya, tanpa membuat modulnya terlalu rapat untuk kamera ponsel murah.
 */
class QrImage
{
    public static function svg(string $payload, int $size = 240): string
    {
        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();
        $n = $matrix->getWidth();

        // Satu modul = satu satuan viewBox; peramban yang menskalakan, jadi ukurannya tetap tajam
        // di layar kasir mana pun. Quiet zone 4 modul adalah syarat standar QR — tanpa itu banyak
        // pemindai gagal mengunci kode.
        $quiet = 4;
        $total = $n + 2 * $quiet;

        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= 'M'.($x + $quiet).' '.($y + $quiet).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" '
            .'viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges" role="img" aria-label="Kode QR pembayaran">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/>'
            .'<path d="'.$path.'" fill="#000"/>'
            .'</svg>';
    }
}
