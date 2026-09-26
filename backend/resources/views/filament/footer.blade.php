{{--
    Baris hak cipta di kaki seluruh halaman back-office.

    Dipasang lewat PanelsRenderHook::FOOTER di AdminPanelProvider, bukan disalin ke tiap tata letak:
    satu hook itu dirender oleh layout penuh (layout/index) MAUPUN layout sederhana (layout/simple),
    sehingga halaman masuk, daftar, atur ulang sandi, dan profil ikut mendapatkannya tanpa tambahan apa pun.

    Layar kasir /pos sengaja TIDAK memakai ini (keputusan user 26 Sep 2026): halaman itu di luar panel
    Filament dan tiap piksel kakinya dipakai tombol bayar.

    Tahun & nama penerbit berasal dari config/fnb.php, bukan ditulis di sini, agar judul tab peramban
    dan baris ini tidak pernah menyebut brand yang berbeda.
--}}
<footer class="fnb-footer">
    <p class="fnb-footer__text">
        &copy; {{ config('fnb.brand.year') }} {{ config('fnb.brand.publisher') }}
    </p>
</footer>
