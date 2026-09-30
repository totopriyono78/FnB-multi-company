<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Tenancy\Application\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kasir yang shift-nya sedang terbuka, untuk kartu "Kasir bertugas" di Ringkasan
 * (permintaan user 30 Sep 2026).
 *
 * **Shift terbuka bukan berarti sedang melayani.** Keduanya sengaja dipisah: shift yang lupa
 * ditutup semalam tetap ditampilkan — justru itu yang perlu dilihat pemilik — tetapi diberi
 * penanda bahwa perangkatnya sudah tidak berdenyut. Menyaringnya keluar akan menyembunyikan
 * persoalan yang paling sering terjadi di lapangan.
 *
 * Ambang "terhubung" memakai `fnb.devices.offline_after_seconds` (180 detik), sama dengan kartu
 * Kesehatan Perangkat, supaya dua tempat di layar yang sama tidak pernah berbeda pendapat tentang
 * perangkat yang sama. POS berdenyut tiap 60 detik.
 */
class ActiveCashiers
{
    /**
     * @param  list<string>  $outletIds
     * @return list<array{shift_id: string, cashier: string, outlet: string, device: string, opened_at: string, online: bool, status_label: string, order_count: int, net_sales: string}>
     */
    public function forOutlets(array $outletIds): array
    {
        if ($outletIds === []) {
            return [];
        }
        $companyId = app(TenantContext::class)->companyId();
        if ($companyId === null) {
            return [];
        }

        $shifts = DB::table('shifts as s')
            ->join('devices as d', 'd.id', '=', 's.device_id')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'open')
            ->whereIn('s.outlet_id', $outletIds)
            ->orderBy('s.opened_at')
            ->select([
                's.id', 's.business_date', 's.opened_at',
                'o.name as outlet_name', 'o.code as outlet_code', 'o.timezone',
                'd.name as device_name', 'd.last_seen_at',
                'u.name as cashier_name',
            ])
            ->get();

        if ($shifts->isEmpty()) {
            return [];
        }

        $ids = $shifts->pluck('id')->all();
        $dates = array_values(array_unique($shifts->pluck('business_date')->map(fn ($d) => (string) $d)->all()));
        $sales = $this->sales($companyId, $ids, $dates);
        $refunds = $this->refunds($companyId, $ids);
        $cutoff = CarbonImmutable::now()->subSeconds((int) config('fnb.devices.offline_after_seconds'));

        $rows = [];
        foreach ($shifts as $shift) {
            $seen = $shift->last_seen_at === null ? null : CarbonImmutable::parse($shift->last_seen_at);
            $online = $seen !== null && $seen->greaterThan($cutoff);
            $gross = BigDecimal::of($sales[$shift->id]['gross'] ?? '0');
            $rows[] = [
                'shift_id' => (string) $shift->id,
                // Staf yang akunnya sudah dihapus tetap punya shift; jangan biarkan barisnya kosong.
                'cashier' => is_string($shift->cashier_name) && $shift->cashier_name !== '' ? $shift->cashier_name : 'Staf tidak dikenal',
                'outlet' => (string) $shift->outlet_name.' ('.$shift->outlet_code.')',
                'device' => (string) $shift->device_name,
                // Jam buka dibaca di zona waktu OUTLET-nya, bukan zona pembaca: yang ditanya pemilik
                // adalah "sejak jam berapa kasir itu bertugas di sana".
                'opened_at' => CarbonImmutable::parse($shift->opened_at)->setTimezone((string) $shift->timezone)->format('H.i'),
                'online' => $online,
                /*
                 * Statusnya berupa TEKS, bukan sekadar titik berwarna: WCAG melarang status yang
                 * hanya dibedakan warna. Untuk yang terputus, jam denyut terakhir ikut disebut —
                 * "terputus 5 menit lalu" dan "terputus sejak semalam" menuntut tindakan berbeda.
                 */
                'status_label' => $online
                    ? 'Terhubung'
                    : 'Terputus'.($seen === null
                        ? ', belum pernah online'
                        : ' sejak '.$seen->setTimezone((string) $shift->timezone)->format('H.i')),
                'order_count' => (int) ($sales[$shift->id]['count'] ?? 0),
                // Definisi yang sama dengan layar Shift dan halaman Transaksi: yang dibatalkan tidak
                // ikut dijumlah, dan nilainya dikurangi retur yang dicatat pada shift itu (BR-13).
                'net_sales' => (string) $gross->minus($refunds[$shift->id] ?? '0')->toScale(2),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $shiftIds
     * @param  list<string>  $dates
     * @return array<string, array{count: int, gross: string}>
     */
    private function sales(string $companyId, array $shiftIds, array $dates): array
    {
        // `business_date` ikut disaring supaya Postgres memangkas partisi `orders`; tanpa itu satu
        // kartu dashboard memindai seluruh riwayat.
        return DB::table('orders')
            ->where('company_id', $companyId)
            ->whereIn('shift_id', $shiftIds)
            ->whereIn('business_date', $dates)
            ->groupBy('shift_id')
            ->selectRaw('shift_id, count(*) filter (where status <> ?) as n, coalesce(sum(total) filter (where status <> ?), 0) as gross', [Order::VOIDED, Order::VOIDED])
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->shift_id => ['count' => (int) $r->n, 'gross' => (string) $r->gross]])
            ->all();
    }

    /**
     * @param  list<string>  $shiftIds
     * @return array<string, string>
     */
    private function refunds(string $companyId, array $shiftIds): array
    {
        return DB::table('refunds')
            ->where('company_id', $companyId)
            ->whereIn('shift_id', $shiftIds)
            ->groupBy('shift_id')
            ->selectRaw('shift_id, coalesce(sum(amount), 0) as amount')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->shift_id => (string) $r->amount])
            ->all();
    }
}
