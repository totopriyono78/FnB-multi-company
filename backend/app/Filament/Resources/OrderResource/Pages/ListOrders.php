<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\SalesLabels;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\ReportTable;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Tenancy\Domain\Models\Company;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListOrders extends ListRecords
{
    /**
     * Batas baris satu berkas ekspor. Ekspor menyusun seluruh barisnya di memori lebih dulu, jadi
     * batas ini yang menjaga satu klik tidak menjatuhkan server. Filter periode di halaman ini
     * sudah menyempitkan rentangnya, dan pemakai yang butuh lebih banyak dilayani menu Laporan
     * yang memang meringkas, bukan mendaftar satu per satu.
     */
    private const MAX_ROWS = 10_000;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportXlsx')->label('Excel (.xlsx)')->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->export('xlsx')),
                Action::make('exportPdf')->label('PDF')->icon('heroicon-o-document-text')
                    ->action(fn () => $this->export('pdf')),
            ])->label('Ekspor')->icon('heroicon-o-arrow-down-tray')->button()->color('gray'),
        ];
    }

    /**
     * Ekspor daftar transaksi apa adanya sesuai filter yang sedang aktif (permintaan user
     * 30 Sep 2026).
     *
     * Memakai ReportTable dan ReportExporter yang sama dengan halaman Laporan, jadi bentuk berkas,
     * kepala halaman, dan baris totalnya seragam — dan Excel maupun PDF didapat sekaligus tanpa
     * kode ekspor kedua yang harus dirawat sendiri.
     *
     * Angka totalnya diambil dari OrderResource::summary(), sumber yang sama dengan baris total di
     * layar. Berkas yang menyebut angka berbeda dari layar yang baru saja dilihat pemakainya adalah
     * cara tercepat kehilangan kepercayaan pada keduanya.
     */
    public function export(string $format): ?BinaryFileResponse
    {
        $tz = (string) config('app.display_timezone');
        $query = $this->getFilteredSortedTableQuery();
        $ringkasan = OrderResource::summary($this->getFilteredTableQuery());

        $rows = [];
        foreach ($query->with(['outlet:id,name,code', 'cashier:id,name'])->limit(self::MAX_ROWS)->get() as $order) {
            // Relasi diperiksa dengan instanceof, bukan `?->name ?? '-'`: `??` hanya menahan galat
            // pada operan KIRI-nya, jadi pola itu tetap meledak saat relasinya kosong.
            $kasir = $order->getRelationValue('cashier');
            $outlet = $order->getRelationValue('outlet');
            $rows[] = [
                'waktu' => $order->getAttribute('device_created_at')?->setTimezone($tz)->format('d/m/Y H:i'),
                'receipt_no' => (string) $order->getAttribute('receipt_no'),
                'outlet' => $outlet instanceof Outlet ? $outlet->name : '-',
                'kasir' => $kasir instanceof User ? $kasir->name : '-',
                'channel' => SalesLabels::channel((string) $order->getAttribute('channel_code')),
                'status' => SalesLabels::status((string) $order->getAttribute('status')),
                'total' => (string) $order->getAttribute('total'),
            ];
        }

        if ($rows === []) {
            Notification::make()->warning()->title('Tidak ada transaksi untuk diekspor')
                ->body('Sesuaikan filternya terlebih dahulu.')->send();

            return null;
        }

        $catatan = [
            'Transaksi yang dibatalkan tetap tercantum, tetapi nilainya tidak ikut dijumlah.',
            'Total sudah dikurangi retur, definisinya sama dengan Laporan Penjualan.',
        ];
        if (count($rows) >= self::MAX_ROWS) {
            $catatan[] = 'Hanya '.number_format(self::MAX_ROWS, 0, ',', '.')
                .' transaksi terbaru yang ikut; persempit filternya untuk melihat sisanya.';
        }

        $table = new ReportTable(
            key: 'transaksi',
            title: 'Daftar Transaksi',
            columns: [
                'waktu' => ['label' => 'Waktu', 'type' => ReportTable::TEXT],
                'receipt_no' => ['label' => 'No. struk', 'type' => ReportTable::TEXT],
                'outlet' => ['label' => 'Outlet', 'type' => ReportTable::TEXT],
                'kasir' => ['label' => 'Kasir', 'type' => ReportTable::TEXT],
                'channel' => ['label' => 'Channel', 'type' => ReportTable::TEXT],
                'status' => ['label' => 'Status', 'type' => ReportTable::TEXT],
                'total' => ['label' => 'Total', 'type' => ReportTable::MONEY],
            ],
            rows: $rows,
            totals: ['waktu' => OrderResource::ringkasanTeks($ringkasan), 'total' => $ringkasan['net']],
            summary: [
                ['label' => 'Transaksi dihitung', 'value' => (string) $ringkasan['counted'], 'type' => ReportTable::NUMBER],
                ['label' => 'Total setelah retur', 'value' => $ringkasan['net'], 'type' => ReportTable::MONEY],
                ['label' => 'Rata-rata per transaksi', 'value' => $ringkasan['average'], 'type' => ReportTable::MONEY],
            ],
            notes: $catatan,
        );

        $tenant = Filament::getTenant();
        $file = app(ReportExporter::class)->export(
            $table,
            $format,
            $tenant instanceof Company ? $tenant->name : '',
            now($tz)->format('Ymd'),
        );

        app(AuditLogger::class)->log('transactions.exported', null, new: [
            'format' => $format,
            'rows' => count($rows),
            'counted' => $ringkasan['counted'],
            'net' => $ringkasan['net'],
        ], userId: SalesLabels::user()?->id);

        return response()->download($file['path'], $file['filename'], ['Content-Type' => $file['mime']])
            ->deleteFileAfterSend();
    }
}
