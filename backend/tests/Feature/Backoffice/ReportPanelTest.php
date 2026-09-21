<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\FraudReportPage;
use App\Filament\Pages\Reports\InventoryReportPage;
use App\Filament\Pages\Reports\MenuEngineeringPage;
use App\Filament\Pages\Reports\SalesReportPage;
use App\Filament\Resources\ReportScheduleResource\Pages\CreateReportSchedule;
use App\Filament\Resources\ReportScheduleResource\Pages\ListReportSchedules;
use App\Filament\Resources\ReportScheduleResource\Pages\ViewReportSchedule;
use App\Filament\Widgets\SalesBreakdownToday;
use App\Filament\Widgets\SalesByHourChart;
use App\Filament\Widgets\SalesToday;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use App\Modules\Tenancy\Application\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\ReportFixture;

beforeEach(function () {
    $this->f = ReportFixture::build();
    $this->company = $this->f->pos->company;
    $this->ownerUser = Factory::ownerOf($this->company);
    $this->base = "/admin/{$this->company->code}";
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
});

function reportPanelAs(User $user, Pos $pos): void
{
    test()->actingAs($user);
    Filament::setTenant($pos->company);
    app(TenantContext::class)->setTenant($pos->company->id);
}

// Satu request halaman per kasus (lihat InventoryPanelTest).
it('membuka halaman laporan sesuai peran', function (string $who, string $path, int $status) {
    $user = $who === 'owner' ? $this->ownerUser : $this->f->pos->staff[$who]['user'];
    $this->actingAs($user)->get($this->base.$path)->assertStatus($status);
})->with([
    'pemilik: penjualan' => ['owner', '/laporan/penjualan?dari=2026-09-10&sampai=2026-09-11&tampilan=item', 200],
    'pemilik: menu' => ['owner', '/laporan/menu', 200],
    'pemilik: anti-fraud' => ['owner', '/laporan/anti-fraud?tampilan=events', 200],
    'pemilik: pajak' => ['owner', '/laporan/pajak', 200],
    'pemilik: laba kotor' => ['owner', '/laporan/laba-kotor', 200],
    'pemilik: inventory' => ['owner', '/laporan/inventory?tampilan=stock', 200],
    'pemilik: jadwal' => ['owner', '/laporan/jadwal-email', 200],
    'pemilik: jadwal baru' => ['owner', '/laporan/jadwal-email/create', 200],
    'pemilik: ringkasan' => ['owner', '', 200],
    'manajer: penjualan' => ['manager', '/laporan/penjualan', 200],
    'manajer: jadwal' => ['manager', '/laporan/jadwal-email', 200],
    'kasir: penjualan' => ['cashier', '/laporan/penjualan', 403],
    'kasir: pajak' => ['cashier', '/laporan/pajak', 403],
    'kasir: inventory' => ['cashier', '/laporan/inventory', 403],
    'kasir: jadwal' => ['cashier', '/laporan/jadwal-email', 403],
    'dapur: anti-fraud' => ['kitchen', '/laporan/anti-fraud', 403],
]);

it('menampilkan tabel laporan penjualan dengan filter dari URL', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::withQueryParams(['dari' => ReportFixture::DAY1, 'sampai' => ReportFixture::DAY2, 'tampilan' => 'item'])
        ->test(SalesReportPage::class)
        ->assertSee('Laporan Penjualan — Per item')
        ->assertSee('Croissant')
        ->assertSee('Rp120.000')
        ->assertSee('Rp172.200')
        ->assertSee('69,7%')
        ->set('variant', 'payment')
        ->assertSee('Tunai')
        ->assertSee('Rp198.839,71')
        ->set('outletId', $this->f->pos->otherOutlet->id)
        ->assertSee('Tidak ada data pada periode dan cakupan ini.');
});

it('menolak filter tidak valid dengan pesan yang jelas', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::test(SalesReportPage::class)
        ->set('from', '2025-01-01')
        ->set('to', '2026-09-11')
        ->assertSee('Rentang laporan maksimal 1 tahun.')
        ->set('from', '2026-09-12')
        ->assertSee('Tanggal akhir harus sama atau setelah tanggal awal.')
        ->set('from', '2026-09-10')
        ->set('outletId', '019a0000-0000-7000-8000-000000000000')
        ->assertSee('Brand atau outlet tidak ditemukan dalam cakupan Anda.');
});

it('mengunduh ekspor Excel dan PDF dari halaman laporan', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::withQueryParams(['dari' => ReportFixture::DAY1, 'sampai' => ReportFixture::DAY2])
        ->test(FraudReportPage::class)
        ->assertSee($this->f->pos->staff['manager']['user']->name)
        ->assertSee('65,1%')
        ->callAction('exportXlsx')
        ->assertFileDownloaded('laporan-anti-fraud-per-pengguna-20260910-20260911.xlsx')
        ->callAction('exportPdf')
        ->assertFileDownloaded('laporan-anti-fraud-per-pengguna-20260910-20260911.pdf');

    Livewire::withQueryParams(['dari' => ReportFixture::DAY1, 'sampai' => ReportFixture::DAY2, 'tampilan' => 'events'])
        ->test(FraudReportPage::class)
        ->assertSee('Croissant gosong')
        ->assertSee('Salah input meja')
        ->assertSee('Kurang Rp500');
});

it('menampilkan saran menu engineering dan laporan inventory', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::withQueryParams(['dari' => ReportFixture::DAY1, 'sampai' => ReportFixture::DAY2])
        ->test(MenuEngineeringPage::class)
        ->assertSee('Apa yang perlu dilakukan')
        // Menu tanpa resep tidak boleh tampil sebagai margin 100%.
        ->assertSee('Belum ada HPP')
        ->assertDontSee('Star</td>', false);

    Livewire::withQueryParams(['tampilan' => 'stock'])
        ->test(InventoryReportPage::class)
        ->assertSee('Laporan Inventory — Posisi Stok')
        ->assertFormFieldIsHidden('from');
});

it('menampilkan dashboard penjualan hari ini dengan filter outlet', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::test(SalesToday::class)
        ->assertSee('Penjualan hari ini')
        ->assertSee('Rp43.000')
        ->assertSee('Minggu lalu: Rp0')
        ->assertSee('Rp129.200');
    Livewire::test(SalesByHourChart::class)->assertSee('Penjualan per jam');
    Livewire::test(SalesBreakdownToday::class)
        ->assertSee('Menu terlaris hari ini')
        ->assertSee('Croissant')
        ->assertSee('Rp49.639,71');
    Livewire::test(SalesToday::class, ['filters' => ['outlet_id' => $this->f->pos->otherOutlet->id]])
        ->assertSee('Rp0');
    Livewire::test(Dashboard::class)->assertFormFieldExists('outlet_id', 'filtersForm');

    // Kasir tidak melihat widget penjualan.
    reportPanelAs($this->f->pos->staff['cashier']['user'], $this->f->pos);
    expect(SalesToday::canView())->toBeFalse();
});

it('membuat jadwal laporan dan melihat riwayatnya', function () {
    reportPanelAs($this->ownerUser, $this->f->pos);

    Livewire::test(CreateReportSchedule::class)
        ->fillForm([
            'name' => 'Pajak bulanan untuk akuntan',
            'report_key' => 'tax',
            'format' => 'pdf',
            'frequency' => 'monthly',
            'send_time' => '08:30',
            'recipients' => ['akuntan@kantor.test'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $schedule = ReportSchedule::query()->sole();
    expect($schedule)->toMatchArray(['report_key' => 'tax', 'format' => 'pdf', 'frequency' => 'monthly', 'created_by' => $this->ownerUser->id])
        ->and($schedule->recipients)->toBe(['akuntan@kantor.test'])
        ->and($schedule->next_run_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-10-01 08:30');

    Livewire::test(CreateReportSchedule::class)
        ->fillForm(['name' => 'Salah', 'report_key' => 'tax', 'format' => 'pdf', 'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['bukan-email']])
        ->call('create')
        ->assertHasFormErrors(['recipients.0']);

    Livewire::test(ListReportSchedules::class)
        ->assertCanSeeTableRecords([$schedule])
        ->callTableAction('toggle', $schedule);
    expect($schedule->refresh()->is_active)->toBeFalse();

    Livewire::test(ViewReportSchedule::class, ['record' => $schedule->id])
        ->assertSee('Pajak bulanan untuk akuntan')
        ->assertSee('Belum ada pengiriman.');

    // Manajer outlet tidak melihat jadwal pemilik.
    reportPanelAs($this->f->pos->staff['manager']['user'], $this->f->pos);
    Livewire::test(ListReportSchedules::class)->assertCanNotSeeTableRecords([$schedule]);
});
