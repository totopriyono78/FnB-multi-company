<?php

// Uji volume laporan (NFR-PERF-08): 50 outlet × 30 hari × 200 transaksi (300.000 transaksi, 600.000 baris item).
// Jalankan pada basis data TERPISAH, misalnya:
//   createdb fb_multicompany_perf
//   DB_DATABASE=fb_multicompany_perf php artisan migrate:fresh --force
//   DB_DATABASE=fb_multicompany_perf php artisan db:seed --class=PlanSeeder --force
//   DB_DATABASE=fb_multicompany_perf php scripts/perf-reports-volume.php
// Data dibuat langsung dengan SQL (bukan lewat layanan POS) karena yang diukur adalah kecepatan query laporan.

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Application\DashboardReport;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Application\ReportCatalog;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Tenancy\Application\CompanyRegistrar;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains((string) config('database.connections.pgsql.database'), 'perf')) {
    fwrite(STDERR, "Hentikan: jalankan dengan DB_DATABASE yang berisi kata 'perf' (basis data terpisah).\n");
    exit(1);
}

$outlets = (int) ($argv[1] ?? 50);
$days = (int) ($argv[2] ?? 30);
$perDay = (int) ($argv[3] ?? 200);
$ctx = app(TenantContext::class);
$t0 = microtime(true);

$company = $ctx->runAsSystem(function () {
    $existing = Company::query()->where('name', 'Grup Volume Uji')->first();
    if ($existing !== null) {
        return $existing;
    }
    $owner = User::query()->create(['name' => 'Pemilik Volume', 'email' => 'volume-'.Str::random(6).'@contoh.test', 'password' => 'Rahasia123']);

    return app(CompanyRegistrar::class)->register(['name' => 'Grup Volume Uji'], $owner, 'enterprise');
});
$ownerId = (string) DB::table('company_users')->where('company_id', $company->id)->value('user_id');
$cid = $company->id;
$start = now('Asia/Jakarta')->startOfDay()->subDays($days)->format('Y-m-d');
$end = now('Asia/Jakarta')->startOfDay()->subDay()->format('Y-m-d');

$ctx->runAsSystem(function () use ($cid, $ownerId, $outlets, $days, $perDay, $start, $t0) {
    $category = '';
    if (DB::table('orders')->where('company_id', $cid)->exists()) {
        echo "Data volume sudah ada, lewati pembuatan.\n";

        return;
    }
    $brand = (string) Str::uuid7();
    DB::table('brands')->insert(['id' => $brand, 'company_id' => $cid, 'code' => 'VOL', 'name' => 'Brand Volume', 'created_at' => now(), 'updated_at' => now()]);
    $category = (string) Str::uuid7();
    DB::table('menu_categories')->insert(['id' => $category, 'company_id' => $cid, 'brand_id' => $brand, 'name' => 'Umum', 'created_at' => now(), 'updated_at' => now()]);
    $items = [];
    foreach (['Kopi Susu' => 'KS', 'Roti Bakar' => 'RB'] as $name => $sku) {
        $id = (string) Str::uuid7();
        DB::table('items')->insert(['id' => $id, 'company_id' => $cid, 'brand_id' => $brand, 'category_id' => $category, 'type' => 'single', 'sku' => $sku, 'name' => $name, 'short_name' => $name, 'base_price' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        $items[] = $id;
    }
    for ($i = 1; $i <= $outlets; $i++) {
        $o = (string) Str::uuid7();
        DB::table('outlets')->insert(['id' => $o, 'company_id' => $cid, 'brand_id' => $brand, 'code' => sprintf('V%03d', $i), 'name' => sprintf('Outlet Volume %02d', $i),
            'timezone' => 'Asia/Jakarta', 'tax_name' => 'PB1', 'tax_rate' => 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('devices')->insert(['id' => (string) Str::uuid7(), 'company_id' => $cid, 'outlet_id' => $o, 'code' => 'POS01', 'name' => 'Kasir', 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::statement("
        INSERT INTO shifts (id, company_id, outlet_id, device_id, cashier_id, business_date, opening_cash, opened_at, status, closed_at,
            expected_cash, counted_cash, cash_variance, server_received_at, created_at, updated_at)
        SELECT gen_random_uuid(), o.company_id, o.id, d.id, ?::uuid, day::date, 500000, day + interval '6 hours', 'closed', day + interval '22 hours',
            1000000, 1000000 - (CASE WHEN extract(day FROM day)::int % 7 = 0 THEN 500 ELSE 0 END), -(CASE WHEN extract(day FROM day)::int % 7 = 0 THEN 500 ELSE 0 END), now(), now(), now()
        FROM outlets o JOIN devices d ON d.outlet_id = o.id
        CROSS JOIN generate_series(?::date, ?::date + (? - 1), interval '1 day') AS day
        WHERE o.company_id = ?", [$ownerId, $start, $start, $days, $cid]);
    echo 'shift: '.DB::table('shifts')->where('company_id', $cid)->count()."\n";

    DB::statement("
        INSERT INTO orders (id, business_date, company_id, outlet_id, device_id, shift_id, cashier_id, receipt_no, channel_code, status,
            subtotal, item_discount, order_discount, service_charge, tax, rounding, total, paid_total, change_amount, refunded_total,
            tax_name, pricing, totals, flags, device_created_at, completed_at, server_received_at, voided_at, voided_by, void_reason, void_business_date, created_at, updated_at)
        SELECT gen_random_uuid(), s.business_date, s.company_id, s.outlet_id, s.device_id, s.id, s.cashier_id,
            o.code || '-POS01-' || to_char(s.business_date, 'YYMMDD') || '-' || lpad(n::text, 4, '0'),
            CASE WHEN n % 2 = 0 THEN 'dine_in' ELSE 'take_away' END,
            CASE WHEN n % 100 = 0 THEN 'voided' ELSE 'paid' END,
            v.sub, 0, v.disc, 0, v.tax, 0, v.sub - v.disc + v.tax, v.sub - v.disc + v.tax, 0, 0,
            'PB1', '{\"tax_rate\": \"10.00\", \"tax_inclusive\": false}'::jsonb,
            jsonb_build_object('tax_base', (v.sub - v.disc)::text, 'total', (v.sub - v.disc + v.tax)::text),
            CASE WHEN n % 250 = 0 THEN '[\"price_override\"]'::jsonb ELSE '[]'::jsonb END,
            s.business_date + make_interval(hours => 7 + (n % 14), mins => n % 60),
            CASE WHEN n % 100 = 0 THEN NULL ELSE s.business_date + make_interval(hours => 7 + (n % 14), mins => n % 60) END,
            now(),
            CASE WHEN n % 100 = 0 THEN s.business_date + make_interval(hours => 7 + (n % 14), mins => n % 60) END,
            CASE WHEN n % 100 = 0 THEN s.cashier_id END,
            CASE WHEN n % 100 = 0 THEN 'Batal' END,
            CASE WHEN n % 100 = 0 THEN s.business_date END,
            now(), now()
        FROM shifts s JOIN outlets o ON o.id = s.outlet_id
        CROSS JOIN generate_series(1, ?) AS n
        CROSS JOIN LATERAL (SELECT (20000 + (n % 5) * 15000)::numeric AS sub,
            CASE WHEN n % 20 = 0 THEN ((20000 + (n % 5) * 15000) * 0.1)::numeric ELSE 0 END AS disc) AS base
        CROSS JOIN LATERAL (SELECT base.sub, base.disc, round((base.sub - base.disc) * 0.1, 2) AS tax) AS v
        WHERE s.company_id = ?", [$perDay, $cid]);
    echo 'transaksi: '.DB::table('orders')->where('company_id', $cid)->count().' ('.round(microtime(true) - $t0, 1)." dtk)\n";

    foreach ([[$items[0], 'KS', 'Kopi Susu', 0.6, 1], [$items[1], 'RB', 'Roti Bakar', 0.4, 2]] as [$item, $sku, $name, $share, $line]) {
        DB::statement("
            INSERT INTO order_items (id, business_date, order_id, company_id, line_no, item_id, category_id, item_type, sku, name, qty, unit_price, catalog_price,
                gross, item_discount, order_discount, net, status, modifiers, created_at)
            SELECT gen_random_uuid(), o.business_date, o.id, o.company_id, ?, ?::uuid, ?::uuid, 'single', ?, ?, 1, o.subtotal * ?, o.subtotal * ?,
                o.subtotal * ?, 0, o.order_discount * ?, (o.subtotal - o.order_discount) * ?, CASE WHEN o.status = 'voided' THEN 'voided' ELSE 'sold' END, '[]'::jsonb, now()
            FROM orders o WHERE o.company_id = ?", [$line, $item, $category, $sku, $name, $share, $share, $share, $share, $share, $cid]);
    }
    DB::statement("
        INSERT INTO payments (id, business_date, order_id, company_id, outlet_id, shift_id, method, amount, change_amount, mdr_amount, device_created_at, created_at)
        SELECT gen_random_uuid(), o.business_date, o.id, o.company_id, o.outlet_id, o.shift_id,
            (ARRAY['cash','qris','debit','credit'])[1 + (abs(hashtext(o.id::text)) % 4)], o.total, 0, 0, o.completed_at, now()
        FROM orders o WHERE o.company_id = ? AND o.status <> 'voided'", [$cid]);
    DB::statement("
        INSERT INTO refunds (id, company_id, outlet_id, order_id, order_business_date, business_date, shift_id, amount, method, lines, stock_action,
            reason, refunded_by, flags, device_created_at, server_received_at, created_at)
        SELECT gen_random_uuid(), o.company_id, o.outlet_id, o.id, o.business_date, o.business_date, o.shift_id, round(o.total / 2, 2), 'cash', '[]'::jsonb,
            'return', 'Uji volume', o.cashier_id, '[]'::jsonb, o.completed_at + interval '5 minutes', now(), now()
        FROM orders o WHERE o.company_id = ? AND o.status = 'paid' AND abs(hashtext(o.id::text)) % 150 = 0", [$cid]);
    DB::statement("
        INSERT INTO order_discounts (id, company_id, order_id, business_date, source, type, value, amount, cashier_id, reason, created_at)
        SELECT gen_random_uuid(), o.company_id, o.id, o.business_date, 'manual', 'percent', 10, o.order_discount, o.cashier_id, 'Uji', now()
        FROM orders o WHERE o.company_id = ? AND o.order_discount > 0", [$cid]);
    DB::statement('ANALYZE');
    echo 'item: '.DB::table('order_items')->where('company_id', $cid)->count().', refund: '.DB::table('refunds')->where('company_id', $cid)->count()."\n";
});
printf("Data siap dalam %.1f dtk\n", microtime(true) - $t0);

$ctx->runAsTenant($cid, function () use ($ownerId, $start, $end) {
    $user = User::query()->findOrFail($ownerId);
    auth()->setUser($user);
    $access = app(ReportAccess::class);
    $filter = $access->filter($user, ReportAccess::SALES, ['date_from' => $start, 'date_to' => $end]);
    printf("Filter: %d outlet, %s s.d. %s\n", count($filter->outletIds), $start, $end);
    $measure = function (string $name, callable $fn): void {
        $runs = [];
        for ($i = 0; $i < 3; $i++) {
            $t = microtime(true);
            $rows = $fn();
            $runs[] = (microtime(true) - $t) * 1000;
        }
        sort($runs);
        printf("%-34s median %8.0f ms  maks %8.0f ms  (%s baris)\n", $name, $runs[1], $runs[2], $rows);
    };
    $catalog = app(ReportCatalog::class);
    foreach (['sales.day', 'sales.hour', 'sales.outlet', 'sales.item', 'sales.cashier', 'sales.payment', 'tax', 'fraud', 'fraud.events', 'gross_profit', 'menu_engineering'] as $key) {
        $measure($key, fn () => count($catalog->build($key, $filter)->rows));
    }
    $measure('dashboard (hari terakhir)', fn () => count(app(DashboardReport::class)->build($filter, CarbonImmutable::now()->subDay())['outlets']));
    $measure('ekspor xlsx sales.outlet', function () use ($catalog, $filter) {
        $f = app(ReportExporter::class)->export($catalog->build('sales.outlet', $filter), 'xlsx', 'Volume', 'x');
        unlink($f['path']);

        return 50;
    });
    $measure('ekspor pdf tax', function () use ($catalog, $filter) {
        $f = app(ReportExporter::class)->export($catalog->build('tax', $filter), 'pdf', 'Volume', 'x');
        unlink($f['path']);

        return 50;
    });
});
