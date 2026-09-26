@if ((bool) config('fnb.pos_web', true))
    <a href="{{ url('/pos') }}"
       target="_blank"
       rel="noopener"
       class="fnb-pos-link"
       aria-label="Buka aplikasi kasir POS di tab baru">
        <span class="fnb-pos-link__icon">
            <x-filament::icon icon="heroicon-m-computer-desktop" class="h-5 w-5" />
        </span>
        <span class="fnb-pos-link__text">
            <span class="fnb-pos-link__title">Aplikasi Kasir (POS)</span>
            <span class="fnb-pos-link__sub">Layar kasir outlet — login dengan PIN, bukan dengan akun back-office.</span>
        </span>
        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" style="flex:0 0 auto" />
    </a>
@endif
