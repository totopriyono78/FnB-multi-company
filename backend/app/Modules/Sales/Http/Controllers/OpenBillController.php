<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Sales\Application\BusinessCalendar;
use App\Modules\Sales\Application\SalesException;
use App\Modules\Sales\Domain\Models\OpenBill;
use App\Modules\Sales\Http\Requests\StoreOpenBillRequest;
use App\Modules\Sales\Http\Resources\SalesResources;
use App\Modules\Shared\Http\ApiResponse;
use App\Modules\Shared\Http\Controller;
use App\Modules\Tenancy\Domain\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Tagihan terbuka / parkir bill (FR-POS-12).
 *
 * Dipakai di resto yang tamunya makan dulu lalu membayar belakangan: kasir menyimpan pesanan,
 * melayani tamu lain, lalu membuka kembali tagihan itu saat diminta bill.
 *
 * Tagihan terbuka bukan catatan keuangan — tidak ada uang yang diakui sampai dibayar. Karena itu
 * ia boleh diubah, dan baru menjadi transaksi resmi ketika dilunasi lewat `POST /pos/orders`
 * dengan menyebut `open_bill_id`.
 */
class OpenBillController extends Controller
{
    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /** Daftar tagihan yang masih terbuka di outlet perangkat ini (tanpa rincian baris). */
    public function index(Request $request): JsonResponse
    {
        $bills = OpenBill::query()
            ->where('outlet_id', $this->device($request)->outlet_id)
            ->whereNull('closed_at')
            ->with('openedBy:id,name')
            ->orderBy('opened_at')
            ->limit(200)
            ->get();

        return ApiResponse::ok($bills->map(fn (OpenBill $b) => SalesResources::openBill($b, detail: false))->values()->all());
    }

    public function show(Request $request, string $billId): JsonResponse
    {
        return ApiResponse::ok(SalesResources::openBill($this->find($request, $billId)));
    }

    /** Simpan tagihan baru atau perbarui yang sudah ada (id dibuat perangkat, jadi kiriman ulang aman). */
    public function store(StoreOpenBillRequest $request): JsonResponse
    {
        $data = $request->validated();
        $device = $this->device($request);
        $user = $this->actor($request);

        /** @var OpenBill|null $existing */
        $existing = OpenBill::query()->whereKey($data['id'])->first();
        if ($existing !== null) {
            if ($existing->outlet_id !== $device->outlet_id) {
                throw new SalesException('NOT_FOUND', 'Data tidak ditemukan.', 404);
            }
            if (! $existing->isOpen()) {
                throw new SalesException('OPEN_BILL_CLOSED', 'Tagihan ini sudah dibayar dan tidak bisa diubah lagi.', 409, field: 'id');
            }
        }

        $bill = $existing ?? new OpenBill;
        $bill->forceFill([
            'id' => $data['id'],
            'company_id' => $device->company_id,
            'outlet_id' => $device->outlet_id,
            'device_id' => $device->id,
            'label' => $data['label'] ?? null,
            'table_label' => $data['table_label'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'note' => $data['note'] ?? null,
            'queue_no' => $data['queue_no'] ?? null,
            'channel_code' => $data['channel_code'],
            'lines' => $data['lines'],
            'totals' => $data['totals'] ?? null,
            'updated_at' => now(),
        ]);
        if ($existing === null) {
            $bill->forceFill([
                'business_date' => $this->calendar->businessDate($device->outlet, now()->toImmutable())->format('Y-m-d'),
                'opened_by' => $user->id,
                'opened_at' => now(),
                'created_at' => now(),
            ]);
        }
        $bill->save();

        $this->audit->log($existing === null ? 'pos.open_bill_saved' : 'pos.open_bill_updated', $device, metadata: [
            'open_bill_id' => $bill->id,
            'label' => $bill->label,
            'lines' => count($bill->lines),
        ]);

        $bill->load('openedBy:id,name');

        return $existing === null
            ? ApiResponse::created(SalesResources::openBill($bill))
            : ApiResponse::ok(SalesResources::openBill($bill));
    }

    /** Batalkan tagihan yang belum dibayar. Tidak ada uang yang terlibat, tetapi tetap dicatat. */
    public function destroy(Request $request, string $billId): JsonResponse
    {
        $bill = $this->find($request, $billId);
        if (! $bill->isOpen()) {
            throw new SalesException('OPEN_BILL_CLOSED', 'Tagihan ini sudah dibayar dan tidak bisa dibatalkan.', 409);
        }
        $reason = (string) $request->input('reason', '');
        $device = $this->device($request);

        $this->audit->log('pos.open_bill_cancelled', $device, reason: $reason !== '' ? $reason : null, metadata: [
            'open_bill_id' => $bill->id,
            'label' => $bill->label,
            'totals' => $bill->totals,
        ]);
        $bill->delete();

        return ApiResponse::ok(['cancelled' => true, 'id' => $billId]);
    }

    private function find(Request $request, string $billId): OpenBill
    {
        if (! Str::isUuid($billId)) {
            throw new SalesException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
        /** @var OpenBill|null $bill */
        $bill = OpenBill::query()->whereKey($billId)->with('openedBy:id,name')->first();
        if ($bill === null || $bill->outlet_id !== $this->device($request)->outlet_id) {
            throw new SalesException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $bill;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        return $device->loadMissing('outlet');
    }
}
