<x-filament-panels::page>
    <form wire:submit="calculate" class="fnb-stack">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" icon="heroicon-m-calculator">
                Hitung
            </x-filament::button>
        </div>
    </form>

    @if ($problems !== [])
        <div class="fnb-callout fnb-callout--danger" role="alert">
            <p class="fnb-callout__title">Pesanan tidak dapat dihitung</p>
            <ul class="fnb-callout__list">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($result)
        @php($t = $result['totals'])
        <x-filament::section heading="Rincian" :description="'Channel '.$result['channel']['name'].' · '.$result['tax']['name'].' '.rtrim(rtrim($result['tax']['rate'], '0'), '.').'%'.($result['tax']['inclusive'] ? ' (termasuk harga)' : '')">
            <table class="fnb-receipt" aria-label="Rincian harga">
                <thead>
                    <tr>
                        <th scope="col">Menu</th>
                        <th scope="col" class="fnb-num">Jumlah</th>
                        <th scope="col" class="fnb-num">Harga</th>
                        <th scope="col" class="fnb-num">Diskon</th>
                        <th scope="col" class="fnb-num">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['lines'] as $line)
                        <tr>
                            <td>
                                <span class="fnb-receipt__name">{{ $line['name'] }}{{ $line['variant'] ? ' – '.$line['variant']['name'] : '' }}</span>
                                @foreach ($line['modifiers'] as $mod)
                                    <span class="fnb-receipt__sub">{{ $mod['name'] }}@if ((float) $mod['price'] > 0) (+{{ $this->rupiah($mod['price']) }})@endif</span>
                                @endforeach
                            </td>
                            <td class="fnb-num">{{ rtrim(rtrim($line['qty'], '0'), '.') }}</td>
                            <td class="fnb-num">{{ $this->rupiah($line['unit_price']) }}</td>
                            <td class="fnb-num">{{ $this->lineDiscount($line) }}</td>
                            <td class="fnb-num">{{ $this->rupiah($line['gross']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <dl class="fnb-totals">
                <div><dt>Subtotal</dt><dd>{{ $this->rupiah($t['subtotal']) }}</dd></div>
                @if ((float) $t['discount'] > 0)
                    <div><dt>Diskon</dt><dd>-{{ $this->rupiah($t['discount']) }}</dd></div>
                @endif
                @if ((float) $t['service_charge'] > 0)
                    <div><dt>Service charge</dt><dd>{{ $this->rupiah($t['service_charge']) }}</dd></div>
                @endif
                <div><dt>{{ $result['tax']['name'] }}{{ $result['tax']['inclusive'] ? ' (sudah termasuk)' : '' }}</dt><dd>{{ $this->rupiah($t['tax']) }}</dd></div>
                @if ((float) $t['rounding'] != 0)
                    <div><dt>Pembulatan</dt><dd>{{ (float) $t['rounding'] > 0 ? '' : '-' }}{{ $this->rupiah(ltrim($t['rounding'], '-')) }}</dd></div>
                @endif
                <div class="fnb-totals__grand"><dt>Total</dt><dd>{{ $this->rupiah($t['total']) }}</dd></div>
            </dl>

            @if ($result['promotions'] !== [])
                <p class="fnb-muted">Promo diterapkan:
                    {{ collect($result['promotions'])->map(fn ($p) => $p['name'].' ('.$this->rupiah($p['amount']).')')->implode(', ') }}
                </p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
