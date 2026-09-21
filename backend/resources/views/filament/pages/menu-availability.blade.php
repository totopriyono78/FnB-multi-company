<x-filament-panels::page>
    <div class="fnb-availability-toolbar">
        {{ $this->form }}
        <p class="fnb-muted">
            Menu yang ditandai habis tidak bisa dipesan di kasir outlet ini sampai ditandai tersedia kembali.
            Menu yang disembunyikan tidak muncul di layar kasir.
        </p>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
