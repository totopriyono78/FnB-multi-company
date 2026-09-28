<?php

/**
 * Pemeriksa kesiapan TLS keluar untuk panggilan payment gateway.
 *
 * Dibuat 27 Sep 2026 setelah "Payment gateway tidak dapat dihubungi" ternyata berarti
 * `cURL error 60: unable to get local issuer certificate` — PHP di komputer pengembang belum
 * diberi daftar CA root. Masalah ini tidak akan pernah tertangkap uji otomatis, karena semua uji
 * memakai Http::fake() (sandbox AINO memindahkan uang sungguhan, jadi tidak boleh dipanggil dari uji).
 * Karena itu tiap lingkungan baru — laptop, Railway, server klien — perlu diperiksa sekali.
 *
 * Sengaja TIDAK memuat Laravel: skrip ini harus tetap berguna saat aplikasinya sendiri belum
 * bisa jalan. Juga sengaja tidak memakai kredensial dan tidak memanggil satu pun endpoint API —
 * hanya permintaan HEAD ke akar host, jadi tidak ada transaksi yang terbentuk.
 *
 * Jalankan:  php scripts/cek-tls.php
 * Atau host lain:  php scripts/cek-tls.php https://contoh.test
 */
$host = $argv[1] ?? 'https://svc-core-go-dev.ainosi.com';

echo "Pemeriksaan TLS keluar\n";
echo str_repeat('=', 60), "\n\n";

echo 'PHP            : ', PHP_VERSION, ' (', PHP_OS_FAMILY, ")\n";
echo 'php.ini terbaca: ', php_ini_loaded_file() ?: '(tidak ada!)', "\n";

$cainfo = ini_get('curl.cainfo');
$cafile = ini_get('openssl.cafile');
$capath = ini_get('openssl.capath');

echo 'curl.cainfo    : ', $cainfo !== '' ? $cainfo : '(KOSONG)', "\n";
echo 'openssl.cafile : ', $cafile !== '' ? $cafile : '(KOSONG)', "\n";
echo 'openssl.capath : ', $capath !== '' ? $capath : '(kosong)', "\n";

foreach (['curl.cainfo' => $cainfo, 'openssl.cafile' => $cafile] as $nama => $berkas) {
    if ($berkas !== '' && ! is_readable($berkas)) {
        echo "  !! {$nama} menunjuk berkas yang tidak bisa dibaca.\n";
    }
}
echo "\n";

/** Permintaan HEAD tanpa kredensial; yang diuji hanya jabat tangan TLS. */
$coba = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $galat = curl_error($ch);
    $nomor = curl_errno($ch);
    $kode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['ok' => $galat === '', 'galat' => $galat, 'nomor' => $nomor, 'kode' => $kode];
};

// Host netral dulu: membedakan "daftar CA tidak ada sama sekali" dari "rantai sertifikat
// host tujuan yang kurang lengkap". Keduanya memberi cURL error 60, tetapi obatnya berbeda.
$netral = $coba('https://www.google.com');
$tujuan = $coba($host);

/**
 * Kelompokkan hasil: 'ok', 'sertifikat', atau 'jaringan'.
 *
 * Pemisahan ini penting — obatnya berbeda jauh. Versi pertama skrip ini menyimpulkan "daftar CA
 * tidak ada" untuk SEMUA kegagalan, termasuk proxy yang menolak CONNECT (errno 56); kesimpulan
 * seperti itu mengirim orang memperbaiki php.ini padahal masalahnya jaringan.
 */
$jenis = static function (array $hasil): string {
    if ($hasil['ok']) {
        return 'ok';
    }

    // 60 = peer failed verification, 58/77/80 = masalah berkas sertifikat, 35 = gagal jabat tangan.
    return in_array($hasil['nomor'], [35, 58, 60, 77, 80, 83], true) ? 'sertifikat' : 'jaringan';
};

$jNetral = $jenis($netral);
$jTujuan = $jenis($tujuan);

$ringkas = static function (array $h): string {
    return $h['ok'] ? 'OK (HTTP '.$h['kode'].')' : "GAGAL [cURL {$h['nomor']}] {$h['galat']}";
};

printf("Uji host netral (google.com)%s: %s\n", str_repeat(' ', 4), $ringkas($netral));
printf("Uji host tujuan (%s): %s\n", parse_url($host, PHP_URL_HOST), $ringkas($tujuan));

echo "\n", str_repeat('-', 60), "\n";

$petunjukCa = static function (): void {
    echo "\n  1. Unduh https://curl.se/ca/cacert.pem, simpan mis. C:\\php\\extras\\ssl\\cacert.pem\n";
    echo "  2. Di php.ini (lihat 'php.ini terbaca' di atas) isi keduanya:\n";
    echo "       curl.cainfo = \"C:\\php\\extras\\ssl\\cacert.pem\"\n";
    echo "       openssl.cafile = \"C:\\php\\extras\\ssl\\cacert.pem\"\n";
    echo "  3. Mulai ulang `php artisan serve`, lalu jalankan skrip ini lagi.\n\n";
    echo "  JANGAN mematikan verifikasi TLS sebagai jalan pintas: ini jalur pembayaran.\n";
};

/*
 * Kesimpulan ditentukan oleh PASANGAN hasil, dan semua sembilan kombinasi ditutup. Versi
 * sebelumnya memakai rangkaian if yang menyisakan celah, lalu kasus yang tidak terduga jatuh ke
 * cabang terakhir dan mencetak kesimpulan yang percaya diri tetapi keliru ("host tujuan lolos"
 * padahal gagal). Diagnosis yang salah lebih buruk daripada tidak ada diagnosis.
 */
[$verdikt, $sertakanPetunjukCa, $kode] = match ("{$jNetral}|{$jTujuan}") {
    'ok|ok' => [
        "TLS keluar sehat. Kalau pembuatan QR masih gagal, sebabnya bukan sertifikat —\n"
        .'            baca storage/logs/laravel.json.log untuk pesan dari AINO.', false, 0,
    ],
    'sertifikat|sertifikat' => [
        'PHP belum punya daftar CA root — semua HTTPS keluar gagal diverifikasi, bukan cuma AINO.', true, 1,
    ],
    'ok|sertifikat' => [
        "Daftar CA di mesin ini sehat (host netral lolos), tetapi sertifikat host tujuan tidak\n"
        ."            bisa diverifikasi. Kemungkinan rantainya kurang intermediate. Sampaikan pesan\n"
        .'            galat di atas apa adanya ke penyedia gateway.', false, 1,
    ],
    'ok|jaringan' => [
        "Internet keluar jalan, tetapi host tujuan tidak bisa dihubungi. Kemungkinan firewall\n"
        .'            atau allowlist memblokir host itu, atau servernya sedang mati.', false, 1,
    ],
    'jaringan|jaringan' => [
        "Tidak ada koneksi HTTPS keluar sama sekali — ini soal JARINGAN, bukan sertifikat.\n"
        ."            Periksa internet, DNS, proxy, atau firewall. Jangan mengubah php.ini dulu:\n"
        .'            daftar CA belum tentu bermasalah karena belum sempat diuji.', false, 1,
    ],
    'jaringan|sertifikat' => [
        "Host tujuan gagal di verifikasi sertifikat, TETAPI uji pembanding tidak bisa dijalankan\n"
        ."            karena jaringannya terhalang — jadi belum bisa dipastikan apakah daftar CA mesin\n"
        .'            ini yang kurang atau rantai host tujuan. Betulkan jaringan dulu, lalu ulangi.', false, 1,
    ],
    'jaringan|ok' => [
        "Host tujuan bisa dihubungi, tetapi host pembanding tidak. Jaringan ini tampaknya menyaring\n"
        .'            keluar secara selektif. Untuk keperluan gateway, ini sudah cukup.', false, 0,
    ],
    'sertifikat|ok' => [
        "Aneh: host pembanding gagal verifikasi sertifikat sementara host tujuan lolos. Biasanya\n"
        ."            ini tanda ada perangkat yang menyadap TLS untuk sebagian host. Periksa dengan\n"
        .'            administrator jaringan sebelum memakai jalur ini untuk pembayaran.', false, 1,
    ],
    default => [
        "Hasilnya campuran dan tidak bisa disimpulkan otomatis. Baca dua baris hasil di atas\n"
        .'            apa adanya; jangan mengubah php.ini sebelum tahu mana yang gagal karena apa.', false, 1,
    ],
};

echo 'KESIMPULAN: ', $verdikt, "\n";
if ($sertakanPetunjukCa) {
    $petunjukCa();
}

exit($kode);
