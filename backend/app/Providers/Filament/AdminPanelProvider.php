<?php

namespace App\Providers\Filament;

use App\Filament\DesignTokens;
use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\SetFilamentTenant;
use App\Modules\Tenancy\Domain\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName($this->brandMarkup())
            /*
             * SVG, bukan .ico: satu berkas 1,4 KB tetap tajam dari 16 px sampai ikon layar utama,
             * dan otomatis ikut bila tema warnanya diubah. public/favicon.ico tetap disediakan
             * karena peramban meminta alamat itu sendiri walau tidak ada <link>.
             *
             * Closure, bukan nilai langsung (temuan tim penguji 29 Sep 2026): metode ini dipanggil
             * saat panel didaftarkan, dan pendaftaran provider terjadi SEBELUM middleware berjalan.
             * Saat itu TrustProxies belum sempat membaca X-Forwarded-Proto, jadi Laravel masih
             * melihat request sebagai http:// — dan alamat favicon-nya ikut lahir sebagai http://
             * di halaman https, yang diblokir peramban sebagai mixed content. Aset Filament lain
             * tidak kena karena baru dirakit saat halaman dirender, jauh setelah middleware.
             * Ditunda ke saat render, skemanya benar.
             */
            ->favicon(fn (): string => asset('img/favicon.svg'))
            ->login(Login::class)
            ->registration()
            ->passwordReset()
            /*
             * Halaman profil pengguna WAJIB memakai tata letak sederhana selama panel ini
             * multi-tenant. Rute profil didaftarkan Filament di LUAR awalan `{tenant}`
             * (lihat vendor/filament/filament/routes/web.php), sehingga saat halamannya
             * dibuka tidak ada tenant aktif. Tata letak penuh ikut membangun sidebar, dan
             * setiap tautan menu memanggil `getUrl()` yang menuntut parameter `tenant` —
             * hasilnya UrlGenerationException dan layar 500. Dijaga PanelAccessTest.
             */
            ->profile(isSimple: true)
            ->tenant(Company::class, slugAttribute: 'code', ownershipRelationship: 'company')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(EditCompanyProfile::class)
            ->tenantMiddleware([SetFilamentTenant::class], isPersistent: true)
            // Warna final ditimpa oleh token di resources/css/filament/admin/theme.css.
            ->colors([
                'primary' => Color::hex(DesignTokens::PRIMARY),
                'gray' => Color::hex(DesignTokens::NEUTRAL_TEXT),
                'danger' => Color::hex(DesignTokens::DANGER),
                'warning' => Color::hex(DesignTokens::WARNING),
                'success' => Color::hex(DesignTokens::SUCCESS),
                'info' => Color::hex(DesignTokens::INFO),
            ])
            // Gaya Vuexy (keputusan user 24 Sep 2026): huruf Montserrat.
            ->font('Montserrat')
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            // Mode SPA: pindah halaman lewat Livewire navigate — hanya isi halaman yang diambil,
            // CSS/JS/huruf tidak dimuat ulang. Layar kasir (/pos) selalu dimuat penuh.
            ->spa(fn (): bool => (bool) config('fnb.spa', true))
            ->spaUrlExceptions(fn (): array => [url('/pos'), url('/pos').'*'])
            ->navigationGroups([
                NavigationGroup::make('Organisasi'),
                NavigationGroup::make('Menu & Harga'),
                NavigationGroup::make('Penjualan'),
                NavigationGroup::make('Laporan'),
                NavigationGroup::make('Inventory'),
                NavigationGroup::make('Pembelian'),
                NavigationGroup::make('Akuntansi'),
                // Dokumen berdiri sendiri, bukan di dalam Akuntansi: yang membuka SPPK sehari-hari
                // adalah manajer outlet yang tidak punya dan tidak butuh akses pembukuan.
                NavigationGroup::make('Dokumen'),
                // Kas, bank, hutang & piutang berdiri sendiri: yang membukanya tiap hari adalah
                // finance yang mengurus uang, bukan yang menyusun laporan.
                NavigationGroup::make('Kas & Hutang'),
                /*
                 * Holding & konsolidasi berdiri paling jauh dari yang lain, dan itu disengaja: ia
                 * hanya muncul bagi entitas holding, dan satu-satunya orang yang membukanya tidak
                 * punya akses ke satu pun layar transaksi di atasnya.
                 */
                NavigationGroup::make('Holding & Konsolidasi'),
                NavigationGroup::make('Akuntansi (Prototipe)'),
                NavigationGroup::make('Pengguna & Akses'),
                NavigationGroup::make('Keamanan'),
            ])
            // Pintasan ke aplikasi kasir (POS web); dibuka di tab baru agar layar kasir tetap utuh.
            ->navigationItems([
                NavigationItem::make('Aplikasi Kasir (POS)')
                    ->url(fn (): string => url('/pos'), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-computer-desktop')
                    ->group('Penjualan')
                    ->sort(99)
                    /*
                     * Dijaga izin, bukan hanya saklar fitur. Sebelumnya pintasan ini muncul bagi
                     * SIAPA PUN yang bisa masuk back-office — termasuk finance, gudang, dan
                     * konsolidator, yang tak satu pun bisa bertransaksi di POS. Aplikasinya memang
                     * akan menolak mereka, tetapi menu yang menjanjikan sesuatu lalu menolaknya
                     * adalah cara paling cepat membuat orang berhenti percaya pada menunya.
                     */
                    ->visible(fn (): bool => (bool) config('fnb.pos_web', true)
                        && auth()->user()?->can('pos.transact') === true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Pintasan ke aplikasi kasir (POS web) dari halaman login; ditempatkan tepat di
            // bawah tombol Masuk agar tidak tenggelam di bawah daftar akun demo.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View => view('filament.auth.pos-link'),
                scopes: Login::class,
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View => view('filament.auth.demo-accounts'),
                scopes: Login::class,
            )
            // Tombol ikon bawaan Filament tanpa nama aksesibel (WCAG 4.1.2) diberi label Bahasa Indonesia.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.a11y-labels'))
            /*
             * Baris hak cipta. Hook FOOTER dirender oleh layout penuh MAUPUN layout sederhana,
             * jadi satu pendaftaran ini sudah menutup semua halaman panel termasuk masuk,
             * daftar, atur ulang sandi, dan profil.
             */
            ->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('filament.footer'))
            ->authMiddleware([
                Authenticate::class,
                // Sesudah Authenticate: penanda yang diperiksanya ada pada pengguna yang sudah masuk.
                ForcePasswordChange::class,
            ]);
    }

    /**
     * Nama brand: judul tab peramban sekaligus teks di kepala sidebar dan halaman masuk.
     *
     * Filament menyusun judul tab sebagai "<nama halaman> - <brandName>" dan membersihkannya
     * dengan strip_tags (vendor/filament/filament/resources/views/components/layout/base.blade.php),
     * sementara komponen logo memasang nilainya apa adanya bila berupa Htmlable. Keduanya dipakai
     * di sini: judulnya tetap "... - FnB Cloud - Gamatechno" sebagai satu baris polos, tetapi di
     * sidebar — yang hanya selebar 191 px — namanya dipenggal jadi dua baris lewat CSS.
     *
     * Dipenggal, bukan dikecilkan: agar muat satu baris di sidebar, hurufnya harus turun ke 15 px,
     * lebih kecil daripada label menu di bawahnya, sehingga nama produk justru kalah menonjol.
     */
    protected function brandMarkup(): Htmlable
    {
        return new HtmlString(
            e(config('fnb.brand.product'))
            .'<span class="fnb-brand__sep"> - </span>'
            .'<span class="fnb-brand__owner">'.e(config('fnb.brand.short')).'</span>'
        );
    }
}
