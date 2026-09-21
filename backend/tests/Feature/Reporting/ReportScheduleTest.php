<?php

use App\Modules\Audit\Domain\AuditLog;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Reporting\Application\ReportScheduler;
use App\Modules\Reporting\Domain\Models\ReportDelivery;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use App\Modules\Reporting\Mail\ScheduledReportMail;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Factory;
use Tests\Support\Pos;
use Tests\Support\ReportFixture;

beforeEach(function () {
    $this->f = ReportFixture::build();
    $this->company = $this->f->pos->company;
    $this->owner = $this->f->headers('owner');
});

function scheduleBody(array $extra = []): array
{
    return array_replace([
        'name' => 'Penjualan harian pemilik',
        'report_key' => 'sales.day',
        'format' => 'xlsx',
        'frequency' => 'daily',
        'send_time' => '07:00',
        'recipients' => ['pemilik@kopitepi.test', 'Akuntan@Kantor.test'],
    ], $extra);
}

it('menghitung periode dan jadwal kirim berikutnya di zona waktu company', function () {
    $now = CarbonImmutable::parse('2026-09-16 08:00', 'Asia/Jakarta'); // Rabu
    expect(array_map(fn ($d) => $d->format('Y-m-d'), ReportScheduler::period('daily', $now)))->toBe(['2026-09-15', '2026-09-15'])
        ->and(array_map(fn ($d) => $d->format('Y-m-d'), ReportScheduler::period('weekly', $now)))->toBe(['2026-09-07', '2026-09-13'])
        ->and(array_map(fn ($d) => $d->format('Y-m-d'), ReportScheduler::period('monthly', $now)))->toBe(['2026-08-01', '2026-08-31']);

    $scheduler = app(ReportScheduler::class);
    $make = fn (string $freq, string $time) => (new ReportSchedule)->forceFill(['company_id' => $this->company->id, 'frequency' => $freq, 'send_time' => $time]);
    $this->f->pos->tenant(function () use ($scheduler, $make, $now): void {
        expect($scheduler->nextRun($make('daily', '07:00'), $now->utc())->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-17 07:00')
            ->and($scheduler->nextRun($make('daily', '09:30'), $now->utc())->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-16 09:30')
            ->and($scheduler->nextRun($make('weekly', '07:00'), $now->utc())->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-21 07:00')
            ->and($scheduler->nextRun($make('monthly', '07:00'), $now->utc())->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-10-01 07:00')
            ->and($scheduler->nextRun($make('daily', '07:00'), $now->utc())->utc()->format('H:i'))->toBe('00:00');
    });
});

describe('API jadwal', function () {
    it('membuat, mengubah, dan menonaktifkan jadwal dengan audit log', function () {
        $created = $this->postJson('/api/v1/report-schedules', scheduleBody(), $this->owner)->assertCreated();
        assertStandardEnvelope($created);
        $id = $created->json('data.id');
        expect($created->json('data'))->toMatchArray([
            'report_label' => 'Penjualan — per hari',
            'recipients' => ['pemilik@kopitepi.test', 'akuntan@kantor.test'],
            'is_active' => true,
            'send_time' => '07:00',
        ])->and(CarbonImmutable::parse($created->json('data.next_run_at'))->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-11 07:00');

        $this->patchJson("/api/v1/report-schedules/{$id}", ['format' => 'pdf', 'frequency' => 'weekly'], $this->owner)
            ->assertOk()->assertJsonPath('data.format', 'pdf')->assertJsonPath('data.frequency', 'weekly')
            ->assertJsonPath('data.recipients.0', 'pemilik@kopitepi.test');

        $this->postJson("/api/v1/report-schedules/{$id}/deactivate", [], $this->owner)
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.next_run_at', null);
        $this->postJson("/api/v1/report-schedules/{$id}/activate", [], $this->owner)->assertOk()->assertJsonPath('data.is_active', true);

        $actions = $this->f->pos->tenant(fn () => AuditLog::query()->where('auditable_id', $id)->pluck('action')->sort()->values()->all());
        expect($actions)->toBe(['report_schedule.activated', 'report_schedule.created', 'report_schedule.deactivated', 'report_schedule.updated']);

        $this->getJson("/api/v1/report-schedules/{$id}", $this->owner)->assertOk()->assertJsonPath('data.deliveries', []);
        expect($this->getJson('/api/v1/report-schedules', $this->owner)->json('meta.pagination.total'))->toBe(1);
    });

    it('memvalidasi isi jadwal', function () {
        $bad = $this->postJson('/api/v1/report-schedules', scheduleBody([
            'name' => '', 'report_key' => 'sales.day', 'format' => 'docx', 'frequency' => 'hourly',
            'send_time' => '25:00', 'recipients' => ['bukan-email'],
        ]), $this->owner)->assertUnprocessable();
        expect(errorFields($bad))->toContain('name', 'format', 'frequency', 'send_time', 'recipients.0');

        $tooMany = array_map(fn ($i) => "orang{$i}@contoh.test", range(1, 11));
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(['recipients' => $tooMany]), $this->owner)->assertUnprocessable()))->toContain('recipients');
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(['report_key' => 'rahasia']), $this->owner)->assertUnprocessable()))->toContain('report_key');
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(['recipients' => []]), $this->owner)->assertUnprocessable()))->toContain('recipients');
    });

    it('menerapkan hak akses & isolasi jadwal', function () {
        $id = $this->postJson('/api/v1/report-schedules', scheduleBody(), $this->owner)->assertCreated()->json('data.id');

        // Kasir tidak punya akses laporan.
        $cashier = $this->f->headers('cashier');
        $this->postJson('/api/v1/report-schedules', scheduleBody(), $cashier)->assertForbidden();
        $this->getJson('/api/v1/report-schedules', $cashier)->assertForbidden();

        // Gudang hanya dapat menjadwalkan laporan inventory.
        [$warehouse] = Factory::staff($this->company, ['warehouse']);
        $wh = asMember($warehouse, $this->company);
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(), $wh)->assertUnprocessable()))->toContain('report_key');
        $this->postJson('/api/v1/report-schedules', scheduleBody(['report_key' => 'inventory.stock']), $wh)->assertCreated();

        // Manajer outlet: jadwal pemilik tidak terlihat; outlet di luar cakupan ditolak.
        $manager = $this->f->headers('manager');
        $this->getJson("/api/v1/report-schedules/{$id}", $manager)->assertNotFound();
        $this->patchJson("/api/v1/report-schedules/{$id}", ['name' => 'Ambil alih'], $manager)->assertNotFound();
        expect($this->getJson('/api/v1/report-schedules', $manager)->json('meta.pagination.total'))->toBe(0);
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(['outlet_id' => $this->f->pos->otherOutlet->id]), $manager)->assertUnprocessable()))->toContain('outlet_id');
        $mine = $this->postJson('/api/v1/report-schedules', scheduleBody(['outlet_id' => $this->f->pos->outlet->id]), $manager)->assertCreated()->json('data.id');

        // Admin company (company.manage) melihat & dapat menonaktifkan jadwal orang lain, tetapi tidak mengubah isinya.
        $admin = $this->f->headers('admin');
        $this->getJson("/api/v1/report-schedules/{$mine}", $admin)->assertOk();
        $this->patchJson("/api/v1/report-schedules/{$mine}", ['name' => 'Diubah admin'], $admin)->assertForbidden();
        $this->postJson("/api/v1/report-schedules/{$mine}/deactivate", [], $admin)->assertOk()
            ->assertJsonPath('data.disabled_reason', 'Dinonaktifkan oleh '.$this->f->pos->staff['admin']['user']->name);
        $this->postJson("/api/v1/report-schedules/{$mine}/activate", [], $admin)->assertForbidden();

        // Company lain.
        $other = Pos::setup('Bakmi Nusantara', 'BKM');
        $otherOwner = asMember(Factory::ownerOf($other->company), $other->company);
        $this->getJson("/api/v1/report-schedules/{$id}", $otherOwner)->assertNotFound();
        $this->postJson("/api/v1/report-schedules/{$id}/deactivate", [], $otherOwner)->assertNotFound();
        expect($this->getJson('/api/v1/report-schedules', $otherOwner)->json('meta.pagination.total'))->toBe(0);
        expect(errorFields($this->postJson('/api/v1/report-schedules', scheduleBody(['outlet_id' => $this->f->pos->outlet->id]), $otherOwner)->assertUnprocessable()))->toContain('outlet_id');
    });
});

describe('pengiriman terjadwal', function () {
    beforeEach(function () {
        Mail::fake();
        $this->id = $this->postJson('/api/v1/report-schedules', scheduleBody(), $this->owner)->assertCreated()->json('data.id');
    });

    it('mengirim laporan kemarin dengan lampiran lalu menjadwalkan hari berikutnya (idempoten)', function () {
        $filesBefore = glob(storage_path('app/private/report-exports/*')) ?: [];
        $this->travelTo(CarbonImmutable::parse('2026-09-11 07:02', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1')->assertSuccessful();

        Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) {
            $mail->assertHasSubject('['.$this->company->name.'] Penjualan harian pemilik — 10 Sep 2026');
            // Berkas lampiran sudah dihapus setelah terkirim; cukup periksa nama & jumlah lampiran.
            expect($mail->attachmentName)->toBe('laporan-penjualan-per-hari-20260910-20260910.xlsx')
                ->and($mail->attachments())->toHaveCount(1);
            $mail->assertSeeInHtml('Rp129.200');
            $mail->assertSeeInText('Penjualan bersih: Rp129.200');

            return $mail->hasTo('pemilik@kopitepi.test') && $mail->hasTo('akuntan@kantor.test')
                && $mail->table->totals['net_sales'] === '129200.00';
        });
        $delivery = $this->f->pos->tenant(fn () => ReportDelivery::query()->sole());
        expect($delivery->status)->toBe('sent')
            ->and($delivery->period_from->format('Y-m-d'))->toBe(ReportFixture::DAY1)
            ->and($delivery->row_count)->toBe(1);
        $schedule = $this->f->pos->tenant(fn () => ReportSchedule::query()->findOrFail($this->id));
        expect($schedule->last_status)->toBe('sent')
            ->and($schedule->next_run_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-12 07:00');
        // Berkas sementara dihapus.
        expect(glob(storage_path('app/private/report-exports/*')) ?: [])->toBe($filesBefore);

        // Dijalankan lagi sebelum jatuh tempo: tidak ada kiriman baru.
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 0');
        Mail::assertSentCount(1);

        // Riwayat pengiriman append-only.
        expect(fn () => $this->f->pos->tenant(fn () => DB::table('report_deliveries')->delete()))->toThrow(QueryException::class);
    });

    it('menyusulkan kiriman yang terlewat satu per satu', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 07:02', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1');
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1');
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 0');

        $periods = $this->f->pos->tenant(fn () => ReportDelivery::query()->orderBy('period_from')->get()->map(fn ($d) => $d->period_from->format('Y-m-d'))->all());
        expect($periods)->toBe([ReportFixture::DAY1, ReportFixture::DAY2]);
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m) => $m->table->totals['net_sales'] === '43000.00');

        // Tertinggal lebih dari 3 hari: hanya kiriman terakhir yang disusulkan.
        $this->travelTo(CarbonImmutable::parse('2026-09-20 08:00', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1');
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1');
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 0');
        $last = $this->f->pos->tenant(fn () => ReportDelivery::query()->orderByDesc('period_from')->limit(2)->get()->map(fn ($d) => $d->period_from->format('Y-m-d'))->all());
        expect($last)->toBe(['2026-09-19', '2026-09-12']);
    });

    it('melewati periode yang sudah terkirim bila jadwal diulang', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-11 07:02', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled');
        $this->f->pos->tenant(fn () => ReportSchedule::query()->whereKey($this->id)->update(['next_run_at' => CarbonImmutable::parse('2026-09-11 07:00', 'Asia/Jakarta')->utc()]));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('dilewati 1');
        Mail::assertSentCount(1);
    });

    it('menonaktifkan jadwal bila pembuat tidak lagi aktif', function () {
        $manager = $this->f->headers('manager');
        $mine = $this->postJson('/api/v1/report-schedules', scheduleBody(['outlet_id' => $this->f->pos->outlet->id]), $manager)->assertCreated()->json('data.id');
        $this->f->pos->tenant(fn () => CompanyUser::query()->whereKey($this->f->pos->staff['manager']['member']->id)->update(['is_active' => false]));

        $this->travelTo(CarbonImmutable::parse('2026-09-11 07:05', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Terkirim 1, gagal 1');

        $schedule = $this->f->pos->tenant(fn () => ReportSchedule::query()->findOrFail($mine));
        expect($schedule->is_active)->toBeFalse()
            ->and($schedule->disabled_reason)->toContain('tidak lagi memiliki akses')
            ->and($this->f->pos->tenant(fn () => ReportDelivery::query()->where('schedule_id', $mine)->value('status')))->toBe('failed');
        Mail::assertSent(ScheduledReportMail::class, 1);
    });

    it('membangun laporan dengan hak akses pembuat saat dikirim', function () {
        // Manajer dipindah ke outlet lain: jadwal untuk outlet lama dinonaktifkan, tidak mengirim data outlet lama.
        $manager = $this->f->headers('manager');
        $mine = $this->postJson('/api/v1/report-schedules', scheduleBody(['outlet_id' => $this->f->pos->outlet->id]), $manager)->assertCreated()->json('data.id');
        $member = $this->f->pos->staff['manager']['member'];
        $this->f->pos->tenant(fn () => DB::table('role_scopes')->where('company_user_id', $member->id)->update(['scope_id' => $this->f->pos->otherOutlet->id]));
        app(AccessScope::class)->flush();

        $this->travelTo(CarbonImmutable::parse('2026-09-11 07:05', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled');

        $schedule = $this->f->pos->tenant(fn () => ReportSchedule::query()->findOrFail($mine));
        expect($schedule->is_active)->toBeFalse()->and($schedule->disabled_reason)->toContain('tidak lagi dalam cakupan');
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $m) => $m->schedule->id === $this->id);
        Mail::assertSentCount(1);
    });

    it('mengulang maksimal tiga kali bila pengiriman gagal', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP tidak tersedia'));
        $this->travelTo(CarbonImmutable::parse('2026-09-11 07:02', 'Asia/Jakarta'));
        $this->artisan('reports:send-scheduled')->expectsOutputToContain('gagal 1');

        $next = fn () => $this->f->pos->tenant(fn () => ReportSchedule::query()->findOrFail($this->id));
        expect($next()->next_run_at->setTimezone('Asia/Jakarta')->format('H:i'))->toBe('07:17');

        foreach (['07:18', '07:34'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-09-11 {$time}", 'Asia/Jakarta'));
            $this->artisan('reports:send-scheduled');
        }
        // Setelah 3 kegagalan, lanjut ke jadwal besok.
        expect($next()->next_run_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'))->toBe('2026-09-12 07:00')
            ->and($next()->last_status)->toBe('failed')
            ->and($this->f->pos->tenant(fn () => ReportDelivery::query()->where('status', 'failed')->count()))->toBe(3)
            ->and($this->f->pos->tenant(fn () => ReportDelivery::query()->value('error')))->toBe('SMTP tidak tersedia');
    });
});
