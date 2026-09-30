@php
    use App\Modules\Reporting\Application\ReportTable;
    $data = $this->data();
@endphp
<x-filament-widgets::widget>
    @if ($data !== null)
        <div class="fnb-dashboard-grid">
            <x-filament::section heading="Menu terlaris hari ini">
                <x-slot name="headerEnd">
                    <x-filament::link :href="$this->reportUrl('item', $data['business_date'])" size="sm">Semua item</x-filament::link>
                </x-slot>
                @if ($data['top_items'] === [])
                    <p class="fnb-muted">Belum ada penjualan hari ini.</p>
                @else
                    <div class="fnb-table-scroll">
                        <table class="fnb-receipt" aria-label="Menu terlaris hari ini">
                            <thead>
                                <tr>
                                    <th scope="col">Item</th>
                                    <th scope="col" class="fnb-num">Terjual</th>
                                    <th scope="col" class="fnb-num">Penjualan bersih</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['top_items'] as $row)
                                    <tr>
                                        <th scope="row" class="fnb-report-table__label">{{ $row['label'] }}</th>
                                        <td class="fnb-num">{{ ReportTable::number((string) $row['qty']) }}</td>
                                        <td class="fnb-num">{{ ReportTable::rupiah((string) $row['net_sales']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>


            <x-filament::section heading="Pembayaran hari ini">
                @if ($data['payments'] === [])
                    <p class="fnb-muted">Belum ada pembayaran hari ini.</p>
                @else
                    <div class="fnb-table-scroll">
                        <table class="fnb-receipt" aria-label="Pembayaran per metode hari ini">
                            <thead>
                                <tr>
                                    <th scope="col">Metode</th>
                                    <th scope="col" class="fnb-num">Jumlah</th>
                                    <th scope="col" class="fnb-num">Diterima</th>
                                    <th scope="col" class="fnb-num">Porsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['payments'] as $row)
                                    <tr>
                                        <th scope="row" class="fnb-report-table__label">{{ $row['label'] }}</th>
                                        <td class="fnb-num">{{ ReportTable::number((string) $row['count']) }}</td>
                                        <td class="fnb-num">{{ ReportTable::rupiah((string) $row['net_received']) }}</td>
                                        <td class="fnb-num">{{ ReportTable::format($row['share'], ReportTable::PERCENT) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            {{-- Muncul untuk pengguna yang memang mengelola lebih dari satu outlet, entah sudah ada
                 penjualan atau belum. Dulu bagian ini ikut lenyap saat belum ada penjualan, sehingga
                 halamannya berubah bentuk sepanjang hari dan pembacanya tidak bisa membedakan
                 "belum ada yang jualan" dari "kartunya memang tidak ada" — dua bagian lain di
                 sebelahnya sudah punya keadaan kosong. --}}
            @if ($data['outlet_count'] > 1)
                <x-filament::section heading="Peringkat outlet hari ini" class="fnb-dashboard-grid__wide">
                    @if ($data['outlets'] === [])
                        <p class="fnb-muted">Belum ada penjualan hari ini.</p>
                    @else
                    <div class="fnb-table-scroll">
                        <table class="fnb-receipt" aria-label="Peringkat outlet hari ini">
                            <thead>
                                <tr>
                                    <th scope="col">Outlet</th>
                                    <th scope="col" class="fnb-num">Transaksi</th>
                                    <th scope="col" class="fnb-num">Penjualan bersih</th>
                                    <th scope="col" class="fnb-num">Porsi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data['outlets'] as $row)
                                    <tr>
                                        <th scope="row" class="fnb-report-table__label">{{ $row['label'] }}</th>
                                        <td class="fnb-num">{{ ReportTable::number((string) $row['orders']) }}</td>
                                        <td class="fnb-num">{{ ReportTable::rupiah((string) $row['net_sales']) }}</td>
                                        <td class="fnb-num">{{ ReportTable::format($row['share'], ReportTable::PERCENT) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-widgets::widget>
