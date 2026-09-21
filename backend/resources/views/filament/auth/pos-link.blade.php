@if ((bool) config('fnb.pos_web', true))
    <a href="{{ url('/pos') }}"
       target="_blank"
       rel="noopener"
       style="display:flex;align-items:center;gap:.75rem;margin-top:1rem;padding:.75rem .9rem;
              border:1px solid rgb(var(--gray-200));border-radius:.6rem;text-decoration:none;
              background:rgb(var(--gray-50));color:rgb(var(--gray-700));"
       aria-label="Buka aplikasi kasir POS di tab baru">
        <span style="flex:0 0 auto;display:grid;place-items:center;width:2.1rem;height:2.1rem;border-radius:.45rem;
                     background:rgb(var(--primary-600));color:#fff;">
            <x-filament::icon icon="heroicon-m-computer-desktop" class="h-5 w-5" />
        </span>
        <span style="flex:1;line-height:1.35">
            <span style="display:block;font-size:.85rem;font-weight:600;color:rgb(var(--gray-900))">Aplikasi Kasir (POS)</span>
            <span style="display:block;font-size:.75rem;">Layar kasir outlet — login dengan PIN, bukan dengan akun back-office.</span>
        </span>
        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" style="flex:0 0 auto" />
    </a>
@endif
