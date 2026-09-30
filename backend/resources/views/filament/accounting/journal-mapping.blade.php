<x-filament-panels::page>
    @php $missing = $this->missing(); @endphp

    @if ($missing !== [])
        <div class="fnb-callout fnb-callout--danger" role="alert">
            <p class="fnb-callout__title">Jurnal penjualan harian belum dapat disusun</p>
            <p>Kejadian berikut belum punya akun tujuan, sehingga jurnal otomatis dilewati saat Tutup Hari:</p>
            <ul class="fnb-callout__list">
                @foreach ($missing as $label)
                    <li>{{ $label }}</li>
                @endforeach
            </ul>
            <p>Setelah dilengkapi, hari-hari yang terlewat dapat disusun ulang lewat perintah
                <code>akuntansi:jurnal-penjualan</code>.</p>
        </div>
    @endif

    <form wire:submit="save" class="fnb-stack">
        {{ $this->form }}

        @if (\App\Filament\Support\AccountingAccess::canManage())
            <div>
                <x-filament::button type="submit" icon="heroicon-m-check">
                    Simpan pemetaan
                </x-filament::button>
            </div>
        @else
            <p class="fnb-muted">Anda hanya dapat melihat pemetaan ini. Perubahan dilakukan oleh pengguna dengan izin mengelola akuntansi.</p>
        @endif
    </form>
</x-filament-panels::page>
