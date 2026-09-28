<?php

namespace Tests\Support;

/** Contoh payload QRIS sungguhan, dipakai beberapa berkas uji. */
final class Qris
{
    /**
     * Dari dokumen AINO "API INTEGRATION - QRIS MPM or VA" v1.0.0 (merchant PT AINO Indonesia).
     * CRC-nya sudah dipastikan cocok — lihat QrisPayloadTest.
     */
    public const CONTOH = '00020101021226570011ID.DANA.WWW011893600915063899391202096389939120303UKE'
        .'51440014ID.CO.QRIS.WWW0215ID20243208378400303UKE520489995303360540115802ID'
        .'5917PT AINO Indonesia6015Kota Yogyakarta61055522362720115YmYu3ZozJT5i9si6049'
        .'0011ID.DANA.WWW0425MER2021071400774509608641050116304CCE3';
}
