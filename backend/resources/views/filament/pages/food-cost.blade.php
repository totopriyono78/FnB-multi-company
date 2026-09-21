@php
    $actual = $this->actual();
    $menu = $this->menu();
    $rp = fn ($v) => \App\Filament\Support\MenuFields::rupiah((string) $v);
    $pct = fn ($v) => $v === null ? '-' : str_replace('.', ',', $v).'%';
    $num = fn ($v) => \App\Filament\Support\InventoryFields::number($v);
    $row = $actual['outlets'][0] ?? null;
@endphp

<x-filament-panels::page>
    <div class="fnb-availability-toolbar">
        {{ $this->form }}
    </div>

    <x-filament::section heading="Food cost aktual" description="Pemakaian bahan dibanding penjualan bersih (tanpa pajak, service charge, dan pembulatan).">
        @if (! $row)
            <p class="fnb-muted">Belum ada penjualan atau pemakaian bahan pada periode ini.</p>
        @else
            <dl class="fnb-totals">
                <div><dt>Penjualan bersih</dt><dd>{{ $rp($row['net_sales']) }}</dd></div>
                <div><dt>Pemakaian sesuai resep</dt><dd>{{ $rp($row['theoretical_cost']) }} · {{ $pct($row['theoretical_percent']) }}</dd></div>
                <div><dt>Waste</dt><dd>{{ $rp($row['waste_cost']) }}</dd></div>
                <div><dt>Selisih opname & penyesuaian</dt><dd>{{ $rp($row['variance_cost']) }}</dd></div>
                <div class="fnb-totals__grand"><dt>Food cost aktual</dt><dd>{{ $rp($row['actual_cost']) }} · {{ $pct($row['actual_percent']) }}</dd></div>
            </dl>

            @if ($actual['top_ingredients'] !== [])
                <table class="fnb-receipt" aria-label="Bahan dengan pemakaian terbesar">
                    <caption class="fnb-muted">Bahan dengan nilai pemakaian terbesar</caption>
                    <thead>
                        <tr>
                            <th scope="col">Bahan</th>
                            <th scope="col" class="fnb-num">Jumlah dipakai</th>
                            <th scope="col" class="fnb-num">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($actual['top_ingredients'] as $ing)
                            <tr>
                                <td>{{ $ing['name'] }}</td>
                                <td class="fnb-num">{{ $num($ing['qty']) }} {{ $ing['base_unit'] }}</td>
                                <td class="fnb-num">{{ $rp($ing['value']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Food cost teoritis per menu" description="HPP resep dengan harga pokok rata-rata di lokasi utama outlet, dibanding harga dine-in sebelum pajak.">
        @if ($menu === [])
            <p class="fnb-muted">Belum ada menu aktif untuk brand outlet ini.</p>
        @else
            <table class="fnb-receipt" aria-label="Food cost per menu">
                <thead>
                    <tr>
                        <th scope="col">Menu</th>
                        <th scope="col" class="fnb-num">Harga sebelum pajak</th>
                        <th scope="col" class="fnb-num">HPP</th>
                        <th scope="col" class="fnb-num">Food cost</th>
                        <th scope="col" class="fnb-num">Margin kotor</th>
                        <th scope="col"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($menu as $m)
                        <tr>
                            <td>
                                <span class="fnb-receipt__name">{{ $m['name'] }}</span>
                                <span class="fnb-receipt__sub">{{ $m['sku'] }}
                                    @if (! $m['has_recipe']) · Belum ada resep, stok tidak terpotong @elseif ($m['missing_cost']) · Sebagian bahan belum punya harga @endif
                                </span>
                            </td>
                            <td class="fnb-num">{{ $rp($m['net_price']) }}</td>
                            <td class="fnb-num">{{ $m['cost'] === null ? '-' : $rp($m['cost']) }}</td>
                            <td class="fnb-num">{{ $pct($m['food_cost_percent']) }}</td>
                            <td class="fnb-num">{{ $m['gross_margin'] === null ? '-' : $rp($m['gross_margin']) }}</td>
                            <td>
                                <x-filament::link
                                    :href="\App\Filament\Pages\RecipeEditor::getUrl(['jenis' => 'item', 'target' => $m['item_id'], 'outlet' => $this->outletId])">
                                    {{ $m['has_recipe'] ? 'Lihat resep' : 'Buat resep' }}
                                </x-filament::link>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
