@php
    $cost = $this->cost();
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah(round((float) $v, 2));
    $num = fn ($v) => \App\Filament\Support\InventoryFields::number($v);
@endphp

<x-filament-panels::page>
    <form wire:submit.prevent class="fnb-stack">
        {{ $this->form }}
    </form>

    @if ($this->target && ! $this->canManage())
        <p class="fnb-muted">Anda hanya dapat melihat resep ini. Perubahan dilakukan pengelola menu brand atau pengelola inventory pusat.</p>
    @endif

    @if ($cost)
        <x-filament::section
            :heading="$this->type === 'ingredient' ? 'HPP per 1 satuan hasil' : 'HPP teoritis per porsi'"
            :description="$cost['basis'] === 'outlet_average' ? 'Harga pokok rata-rata stok di lokasi utama outlet terpilih.' : 'Harga beli terakhir tiap bahan.'">
            @if ($cost['missing_cost'])
                <div class="fnb-callout fnb-callout--danger" role="status">
                    <p class="fnb-callout__title">Sebagian bahan belum punya harga</p>
                    <p>Catat penerimaan barang agar HPP lengkap.</p>
                </div>
            @endif
            <table class="fnb-receipt" aria-label="Rincian HPP resep">
                <thead>
                    <tr>
                        <th scope="col">Bahan</th>
                        <th scope="col" class="fnb-num">Jumlah</th>
                        <th scope="col" class="fnb-num">Harga pokok</th>
                        <th scope="col" class="fnb-num">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cost['ingredients'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="fnb-num">{{ $num($row['qty']) }} {{ $row['base_unit'] }}</td>
                            <td class="fnb-num">{{ (float) $row['unit_cost'] > 0 ? $rp($row['unit_cost']).' / '.$row['base_unit'] : 'Belum ada harga' }}</td>
                            <td class="fnb-num">{{ $rp($row['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <dl class="fnb-totals">
                <div class="fnb-totals__grand"><dt>Total HPP</dt><dd>{{ $rp($cost['total']) }}</dd></div>
            </dl>
        </x-filament::section>
    @elseif ($this->target)
        <p class="fnb-muted">Belum ada resep. Tambahkan bahan lalu klik Simpan Resep.</p>
    @else
        <p class="fnb-muted">Pilih menu, varian, modifier, atau bahan setengah jadi untuk melihat dan menyusun resepnya.</p>
    @endif
</x-filament-panels::page>
