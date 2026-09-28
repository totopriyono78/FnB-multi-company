<?php

namespace App\Modules\Payment\Application;

/**
 * Pemeriksa payload QRIS (EMVCo MPM, spesifikasi QRIS Bank Indonesia).
 *
 * Ada karena kekeliruan yang memakan waktu 27 Sep 2026: QR dari driver `sandbox` dipindai dengan
 * m-banking dan ditolak, "pastikan QR yang discan berlogo QRIS". Gambar QR-nya sendiri benar —
 * isinya yang bukan QRIS. Payload sandbox hanya string tiruan: struktur TLV-nya rusak (tag 26
 * panjangnya "SA", bukan angka), tidak memuat penanda `ID.CO.QRIS.WWW`, dan "CRC"-nya berbunyi
 * `SBOX`. Aplikasi bank membaca QR-nya, gagal mengurai isinya, lalu menolak.
 *
 * Gunanya sekarang: gateway sungguhan yang mengembalikan payload cacat ditolak SEBELUM QR-nya
 * ditampilkan ke pelanggan, bukan setelah pelanggan berdiri di depan kasir dengan ponsel gagal.
 *
 * Kelas ini TIDAK membuat payload QRIS — hanya memeriksa. Membuat payload QRIS berarti menerbitkan
 * alat pembayaran; itu wewenang acquirer/penyelenggara, bukan aplikasi kasir.
 */
class QrisPayload
{
    /** Penanda domestik QRIS pada Merchant Account Information (tag 51). */
    public const MARKER = 'ID.CO.QRIS.WWW';

    /** Mata uang 360 = IDR (ISO 4217). */
    private const IDR = '360';

    /**
     * Benar bila payload berbentuk QRIS yang bisa dibayar aplikasi bank.
     *
     * Yang diperiksa: struktur TLV utuh, diakhiri tag CRC (63) yang cocok, memuat penanda QRIS,
     * mata uang rupiah, dan kode negara ID.
     */
    public static function isValid(string $payload): bool
    {
        return self::reason($payload) === null;
    }

    /** Alasan payload ditolak, atau null bila sah. Dipakai pesan galat & log. */
    public static function reason(string $payload): ?string
    {
        if ($payload === '' || ! preg_match('/^[\x20-\x7E]+$/', $payload)) {
            return 'payload kosong atau memuat karakter di luar ASCII tercetak';
        }

        // CRC selalu 4 karakter heksadesimal terakhir, didahului "6304".
        if (mb_substr($payload, -8, 4) !== '6304') {
            return 'tidak diakhiri tag CRC 6304';
        }

        $tertulis = mb_substr($payload, -4);
        $dihitung = self::crc16(mb_substr($payload, 0, -4));
        if (strcasecmp($tertulis, $dihitung) !== 0) {
            return "CRC tidak cocok (tertulis {$tertulis}, seharusnya {$dihitung})";
        }

        $tlv = self::parse($payload);
        if ($tlv === null) {
            return 'struktur TLV rusak';
        }

        if (! str_contains($payload, self::MARKER)) {
            return 'tidak memuat penanda '.self::MARKER;
        }
        if (($tlv['53'] ?? null) !== self::IDR) {
            return 'mata uang bukan 360 (IDR)';
        }
        if (($tlv['58'] ?? null) !== 'ID') {
            return 'kode negara bukan ID';
        }

        return null;
    }

    /**
     * Urai TLV tingkat atas. Null bila ada panjang yang bukan angka atau nilai terpotong —
     * dua cacat yang membuat aplikasi bank berhenti membaca.
     *
     * @return array<string, string>|null
     */
    public static function parse(string $payload): ?array
    {
        $hasil = [];
        $i = 0;
        $n = mb_strlen($payload);

        while ($i < $n) {
            if ($i + 4 > $n) {
                return null;
            }
            $tag = mb_substr($payload, $i, 2);
            $panjang = mb_substr($payload, $i + 2, 2);
            if (! ctype_digit($tag) || ! ctype_digit($panjang)) {
                return null;
            }
            $len = (int) $panjang;
            if ($i + 4 + $len > $n) {
                return null;
            }
            $hasil[$tag] = mb_substr($payload, $i + 4, $len);
            $i += 4 + $len;
        }

        return $hasil;
    }

    /** CRC-16/CCITT-FALSE, polinomial 0x1021, nilai awal 0xFFFF — seperti diwajibkan EMVCo. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        foreach (unpack('C*', $data) ?: [] as $byte) {
            $crc ^= $byte << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
