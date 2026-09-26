<?php

namespace App\Providers\Filament;

use App\Filament\DesignTokens;
use App\Filament\InitialsAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\EditCompanyProfile;
use App\Filament\Pages\Tenancy\RegisterCompany;
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
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('FnB Cloud')
            ->login(Login::class)
            ->registration()
            ->passwordReset()
            ->profile(isSimple: false)
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
                    ->visible(fn (): bool => (bool) config('fnb.pos_web', true)),
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
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
