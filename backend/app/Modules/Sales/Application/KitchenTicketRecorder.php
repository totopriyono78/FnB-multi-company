<?php

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Domain\Models\BundleGroupOption;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Sales\Domain\Events\KitchenTicketSent;
use App\Modules\Sales\Domain\Models\KitchenTicket;
use App\Modules\Sales\Domain\Models\Shift;
use App\Modules\Tenancy\Domain\Models\Device;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Menerima tiket "Kirim ke Dapur" dari POS (entitas sinkron `kitchen.send`, FR-POS-20).
 *
 * Kontrak: ID baris tiket sama dengan ID baris pesanan yang nanti dikirim saat bayar, sehingga stok
 * tidak dipotong dua kali. Pesanan boleh belum lunas (dine-in bayar akhir).
 */
class KitchenTicketRecorder
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly Authorizations $auth,
        private readonly BusinessCalendar $calendar,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function record(Device $device, array $input): KitchenTicket
    {
        $validator = Validator::make($input, [
            'id' => ['required', 'uuid'],
            'order_id' => ['required', 'uuid'],
            'shift_id' => ['nullable', 'uuid'],
            'sent_by' => ['required', 'uuid'],
            'sent_at' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.id' => ['required', 'uuid', 'distinct'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.variant_id' => ['nullable', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999', 'decimal:0,3'],
            'lines.*.modifiers' => ['nullable', 'array', 'max:30'],
            'lines.*.modifiers.*.id' => ['required', 'uuid'],
            'lines.*.modifiers.*.qty' => ['required', 'integer', 'between:1,99'],
            'lines.*.bundle' => ['nullable', 'array', 'max:20'],
            'lines.*.bundle.*.option_id' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) {
            throw new SalesException('VALIDATION_FAILED', 'Data tiket dapur tidak valid.', 422, details: ['errors' => $validator->errors()->toArray()]);
        }
        /** @var array<string, mixed> $data */
        $data = $validator->validated();
        $device->loadMissing('outlet');
        $outlet = $device->outlet;
        $sentAt = $this->shifts->time($data['sent_at'], 'sent_at');

        return DB::transaction(function () use ($device, $outlet, $data, $sentAt): KitchenTicket {
            $sender = $this->auth->staff($data['sent_by'], $outlet, 'pos.transact', 'sent_by');

            $businessDate = null;
            if (! empty($data['shift_id'])) {
                $shift = $this->shifts->assertShiftOnDevice(Shift::query()->find($data['shift_id']), $device);
                $businessDate = $shift->business_date;
            }
            $businessDate ??= $this->calendar->businessDate($outlet, $sentAt);

            $lines = $this->resolveLines($outlet->brand_id, $data['lines']);

            $ticket = new KitchenTicket;
            $ticket->forceFill([
                'id' => $data['id'],
                'company_id' => $device->company_id,
                'outlet_id' => $outlet->id,
                'device_id' => $device->id,
                'shift_id' => $data['shift_id'] ?? null,
                'order_id' => $data['order_id'],
                'sent_by' => $sender->id,
                'business_date' => $businessDate->format('Y-m-d'),
                'sent_at' => $sentAt,
                'lines' => $lines,
                'server_received_at' => now(),
            ])->save();

            DB::afterCommit(fn () => KitchenTicketSent::dispatch($ticket->company_id, $ticket->id, $ticket->order_id));

            return $ticket;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $requested
     * @return list<array<string, mixed>>
     */
    private function resolveLines(string $brandId, array $requested): array
    {
        $items = Item::withTrashed()->with('variants')
            ->whereIn('id', array_values(array_unique(array_column($requested, 'item_id'))))
            ->get()->keyBy('id');
        $modifierIds = [];
        $optionIds = [];
        foreach ($requested as $line) {
            foreach ($line['modifiers'] ?? [] as $m) {
                $modifierIds[$m['id']] = true;
            }
            foreach ($line['bundle'] ?? [] as $b) {
                $optionIds[$b['option_id']] = true;
            }
        }
        $modifiers = Modifier::query()->whereIn('id', array_keys($modifierIds))->pluck('id')->flip();
        $options = BundleGroupOption::query()->with('group:id,item_id')->whereIn('id', array_keys($optionIds))->get()->keyBy('id');

        $lines = [];
        foreach ($requested as $i => $line) {
            /** @var Item|null $item */
            $item = $items->get($line['item_id']);
            if ($item === null || $item->brand_id !== $brandId) {
                throw new SalesException('ITEM_UNKNOWN', 'Menu tidak dikenal untuk outlet ini.', 422, field: "lines.{$i}.item_id");
            }
            $variantId = $line['variant_id'] ?? null;
            if ($variantId !== null && $item->variants->firstWhere('id', $variantId) === null) {
                throw new SalesException('VARIANT_UNKNOWN', 'Varian tidak dikenal untuk menu ini.', 422, field: "lines.{$i}.variant_id");
            }
            $mods = [];
            foreach ($line['modifiers'] ?? [] as $j => $m) {
                if (! $modifiers->has($m['id'])) {
                    throw new SalesException('MODIFIER_UNKNOWN', 'Pilihan tambahan tidak dikenal.', 422, field: "lines.{$i}.modifiers.{$j}.id");
                }
                $mods[] = ['id' => $m['id'], 'qty' => (int) $m['qty']];
            }
            $bundle = [];
            foreach ($line['bundle'] ?? [] as $j => $b) {
                /** @var BundleGroupOption|null $option */
                $option = $options->get($b['option_id']);
                if ($option === null || $option->group->item_id !== $item->id) {
                    throw new SalesException('BUNDLE_OPTION_UNKNOWN', 'Pilihan paket tidak dikenal untuk menu ini.', 422, field: "lines.{$i}.bundle.{$j}.option_id");
                }
                $bundle[] = ['option_id' => $option->id, 'item_id' => $option->item_id, 'variant_id' => $option->item_variant_id];
            }

            $lines[] = [
                'id' => $line['id'],
                'item_id' => $item->id,
                'variant_id' => $variantId,
                'qty' => (string) BigDecimal::of((string) $line['qty']),
                'modifiers' => $mods,
                'bundle' => $bundle,
                'kitchen_station_id' => $item->kitchen_station_id,
            ];
        }

        return $lines;
    }
}
