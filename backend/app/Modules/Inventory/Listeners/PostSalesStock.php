<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Application\SalesStockPoster;
use App\Modules\Sales\Domain\Events\KitchenTicketSent;
use App\Modules\Sales\Domain\Events\OrderCompleted;
use App\Modules\Sales\Domain\Events\OrderRefunded;
use App\Modules\Sales\Domain\Events\OrderVoided;
use Closure;
use Illuminate\Events\Dispatcher;

use function Illuminate\Support\defer;

/**
 * Menghubungkan event penjualan ke pemotongan stok (FR-INV-04).
 * Pada permintaan HTTP dijalankan setelah respons terkirim (tanpa queue worker, SRS §7.3); di konsol langsung.
 * Kegagalan (termasuk proses yang terhenti sebelum sempat memposting) diulang oleh `inventory:post-sales`.
 */
class PostSalesStock
{
    public function __construct(private readonly SalesStockPoster $poster) {}

    public function completed(OrderCompleted $event): void
    {
        $this->later('completed:'.$event->orderId, fn () => $this->poster->completed($event->companyId, $event->orderId));
    }

    public function kitchen(KitchenTicketSent $event): void
    {
        $this->later('kitchen:'.$event->ticketId, fn () => $this->poster->kitchen($event->companyId, $event->ticketId, $event->orderId));
    }

    public function voided(OrderVoided $event): void
    {
        $this->later('voided:'.$event->orderId, fn () => $this->poster->voided($event->companyId, $event->orderId));
    }

    public function refunded(OrderRefunded $event): void
    {
        $this->later('refunded:'.$event->refundId, fn () => $this->poster->refunded($event->companyId, $event->refundId, $event->orderId));
    }

    private function later(string $key, Closure $work): void
    {
        if (app()->runningInConsole() && ! config('fnb.inventory.defer_in_console')) {
            $work();

            return;
        }
        // Nama unik: event yang sama dalam satu permintaan hanya diproses sekali; `always` agar tetap jalan walau respons gagal.
        defer($work, 'stock:'.$key)->always();
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            OrderCompleted::class => 'completed',
            KitchenTicketSent::class => 'kitchen',
            OrderVoided::class => 'voided',
            OrderRefunded::class => 'refunded',
        ];
    }
}
