<?php

namespace App\Modules\Reporting\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\CompanyUser;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Domain\Models\ReportDelivery;
use App\Modules\Reporting\Domain\Models\ReportSchedule;
use App\Modules\Reporting\Export\ReportExporter;
use App\Modules\Reporting\Mail\ScheduledReportMail;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Jadwal laporan email (FR-RPT-08, ADR 0006).
 *
 * - Jadwal milik pembuatnya; laporan dibangun dengan hak akses pembuat pada saat dikirim.
 * - Pengguna dengan `company.manage` dapat melihat & menonaktifkan jadwal milik orang lain.
 * - Pengiriman idempoten per jadwal-periode; gagal diulang maks. 3 kali (jeda 15 menit).
 */
class ReportScheduler
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ReportAccess $access,
        private readonly ReportCatalog $catalog,
        private readonly ReportExporter $exporter,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function canUse(User $user): bool
    {
        return $this->access->can($user, ReportAccess::SALES) || $this->access->can($user, ReportAccess::INVENTORY);
    }

    public function canViewAll(User $user): bool
    {
        return $user->can('company.manage');
    }

    public function canEdit(User $user, ReportSchedule $schedule): bool
    {
        return WritableCompany::allows() && $schedule->created_by === $user->id && $this->canUse($user);
    }

    public function canToggle(User $user, ReportSchedule $schedule): bool
    {
        return WritableCompany::allows() && ($schedule->created_by === $user->id || $this->canViewAll($user));
    }

    /** @return array<string, string> kunci laporan => label yang boleh dijadwalkan user */
    public function reportOptions(User $user): array
    {
        $out = [];
        foreach (ReportCatalog::all() as $key => $meta) {
            if ($this->access->can($user, $meta['kind'])) {
                $out[$key] = $meta['group'].' — '.$meta['label'];
            }
        }

        return $out;
    }

    /**
     * @param  array{name: string, report_key: string, format: string, frequency: string, send_time?: string|null, brand_id?: string|null, outlet_id?: string|null, recipients: list<string>|string, is_active?: bool}  $data
     */
    public function save(User $actor, array $data, ?ReportSchedule $schedule = null): ReportSchedule
    {
        if ($schedule !== null && ! $this->canEdit($actor, $schedule)) {
            throw new AuthorizationException('Hanya pembuat jadwal yang dapat mengubahnya.');
        }
        if (! WritableCompany::allows() || ! $this->canUse($actor)) {
            throw new AuthorizationException('Anda tidak memiliki akses laporan.');
        }

        $key = (string) $data['report_key'];
        if (! ReportCatalog::exists($key) || ! $this->access->can($actor, ReportCatalog::kind($key))) {
            throw ValidationException::withMessages(['report_key' => 'Laporan tidak tersedia untuk Anda.']);
        }
        if (! array_key_exists((string) $data['format'], ReportExporter::FORMATS)) {
            throw ValidationException::withMessages(['format' => 'Format tidak dikenal.']);
        }
        if (! array_key_exists((string) $data['frequency'], ReportSchedule::FREQUENCIES)) {
            throw ValidationException::withMessages(['frequency' => 'Frekuensi tidak dikenal.']);
        }
        $time = (string) ($data['send_time'] ?? '07:00');
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:00)?$/', $time) !== 1) {
            throw ValidationException::withMessages(['send_time' => 'Jam kirim tidak valid (format JJ:MM).']);
        }
        $time = substr($time, 0, 5);

        // Validasi cakupan brand/outlet dengan aturan yang sama seperti saat laporan dibangun.
        try {
            $this->access->filter($actor, ReportCatalog::kind($key), [
                'brand_id' => $data['brand_id'] ?? null,
                'outlet_id' => $data['outlet_id'] ?? null,
            ]);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([isset($data['outlet_id']) && $data['outlet_id'] ? 'outlet_id' : 'brand_id' => 'Brand/outlet tidak ditemukan dalam cakupan Anda.']);
        }

        $recipients = self::recipients($data['recipients']);
        $name = trim((string) $data['name']);
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Nama jadwal wajib diisi (maks. 100 karakter).']);
        }

        return DB::transaction(function () use ($actor, $data, $schedule, $key, $time, $recipients, $name): ReportSchedule {
            $model = $schedule ?? new ReportSchedule;
            $old = $schedule?->only(['name', 'report_key', 'format', 'frequency', 'send_time', 'brand_id', 'outlet_id', 'recipients', 'is_active']);
            $model->forceFill([
                'name' => $name,
                'report_key' => $key,
                'format' => $data['format'],
                'frequency' => $data['frequency'],
                'send_time' => $time,
                'brand_id' => ($data['brand_id'] ?? null) ?: null,
                'outlet_id' => ($data['outlet_id'] ?? null) ?: null,
                'recipients' => $recipients,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'updated_by' => $actor->id,
                'disabled_reason' => null,
            ]);
            if ($schedule === null) {
                $model->forceFill(['id' => (string) Str::uuid7(), 'company_id' => $this->context->requireCompanyId(), 'created_by' => $actor->id]);
            }
            $model->next_run_at = $model->is_active ? $this->nextRun($model, CarbonImmutable::now()) : null;
            $model->save();

            $new = $model->only(['name', 'report_key', 'format', 'frequency', 'send_time', 'brand_id', 'outlet_id', 'recipients', 'is_active']);
            $this->audit->log($schedule === null ? 'report_schedule.created' : 'report_schedule.updated', $model, old: $old, new: $new, userId: $actor->id);

            return $model;
        });
    }

    public function setActive(User $actor, ReportSchedule $schedule, bool $active): ReportSchedule
    {
        if (! $this->canToggle($actor, $schedule)) {
            throw new AuthorizationException('Anda tidak berwenang mengubah jadwal ini.');
        }
        if ($active && $schedule->created_by !== $actor->id) {
            throw new AuthorizationException('Hanya pembuat jadwal yang dapat mengaktifkannya kembali.');
        }
        $old = ['is_active' => $schedule->is_active];
        $schedule->forceFill([
            'is_active' => $active,
            'next_run_at' => $active ? $this->nextRun($schedule, CarbonImmutable::now()) : null,
            'disabled_reason' => $active ? null : ($schedule->created_by === $actor->id ? null : 'Dinonaktifkan oleh '.$actor->name),
            'updated_by' => $actor->id,
        ])->save();
        $this->audit->log($active ? 'report_schedule.activated' : 'report_schedule.deactivated', $schedule, old: $old, new: ['is_active' => $active], userId: $actor->id);

        return $schedule;
    }

    /**
     * @param  list<string>|string  $input
     * @return list<string>
     */
    public static function recipients(array|string $input): array
    {
        $list = is_array($input) ? $input : preg_split('/[\s,;]+/', $input);
        $out = [];
        foreach ($list ?: [] as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 150) {
                throw ValidationException::withMessages(['recipients' => "Alamat email tidak valid: {$email}"]);
            }
            $out[$email] = true;
        }
        if ($out === []) {
            throw ValidationException::withMessages(['recipients' => 'Isi minimal satu alamat email penerima.']);
        }
        if (count($out) > ReportSchedule::MAX_RECIPIENTS) {
            throw ValidationException::withMessages(['recipients' => 'Maksimal '.ReportSchedule::MAX_RECIPIENTS.' penerima.']);
        }

        return array_keys($out);
    }

    /**
     * Periode laporan untuk pengiriman pada waktu tertentu (zona waktu company).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(string $frequency, CarbonImmutable $localNow): array
    {
        $today = CarbonImmutable::parse($localNow->format('Y-m-d'));

        return match ($frequency) {
            'weekly' => [$today->startOfWeek(CarbonImmutable::MONDAY)->subWeek(), $today->startOfWeek(CarbonImmutable::MONDAY)->subDay()],
            'monthly' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()->subDay()],
            default => [$today->subDay(), $today->subDay()],
        };
    }

    public function nextRun(ReportSchedule $schedule, CarbonImmutable $after): CarbonImmutable
    {
        $tz = $this->companyTimezone($schedule->company_id);
        [$h, $m] = array_map('intval', explode(':', substr((string) $schedule->send_time, 0, 5)));
        $local = $after->setTimezone($tz);
        $candidate = match ($schedule->frequency) {
            'weekly' => $local->startOfWeek(CarbonImmutable::MONDAY)->setTime($h, $m),
            'monthly' => $local->startOfMonth()->setTime($h, $m),
            default => $local->startOfDay()->setTime($h, $m),
        };
        while ($candidate->lessThanOrEqualTo($local)) {
            $candidate = match ($schedule->frequency) {
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow()->startOfMonth()->setTime($h, $m),
                default => $candidate->addDay(),
            };
        }

        return $candidate->setTimezone('UTC');
    }

    /**
     * Kirim semua jadwal yang jatuh tempo (dipanggil perintah terjadwal).
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function runDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $due = $this->context->runAsSystem(fn () => ReportSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->limit(200)
            ->get(['id', 'company_id']));

        foreach ($due as $row) {
            $status = $this->context->runAsTenant($row->company_id, fn () => $this->runOne($row->id, $now));
            if ($status !== null) {
                $result[$status]++;
            }
        }

        return $result;
    }

    private function runOne(string $scheduleId, CarbonImmutable $now): ?string
    {
        // Klaim jadwal (sewa 10 menit) agar proses paralel tidak mengirim dua kali.
        $claim = DB::transaction(function () use ($scheduleId, $now): ?array {
            $s = ReportSchedule::query()->whereKey($scheduleId)->lockForUpdate()->first();
            if ($s === null || ! $s->is_active || $s->next_run_at === null || $s->next_run_at->greaterThan($now)) {
                return null;
            }
            $claimedFor = $s->next_run_at;
            $s->forceFill(['next_run_at' => $now->addMinutes(10)])->save();

            return [$s, $claimedFor];
        });
        if ($claim === null) {
            return null;
        }
        /** @var ReportSchedule $schedule */
        /** @var CarbonImmutable $runAt */
        [$schedule, $runAt] = $claim;

        $tz = $this->companyTimezone($schedule->company_id);
        // Periode ditentukan dari slot jadwal (bukan waktu proses) agar pengulangan & susulan tetap periode yang sama.
        $runAt = $this->slot($schedule, $runAt);
        [$from, $to] = self::period($schedule->frequency, $runAt->setTimezone($tz));

        $already = ReportDelivery::query()->where('schedule_id', $schedule->id)
            ->whereDate('period_from', $from->format('Y-m-d'))->whereDate('period_to', $to->format('Y-m-d'))
            ->where('status', 'sent')->exists();
        if ($already) {
            $this->finish($schedule, 'skipped', $now, $runAt);

            return 'skipped';
        }

        $owner = User::query()->find($schedule->created_by);
        $member = $owner === null ? null : CompanyUser::query()->where('user_id', $owner->id)->first();
        $kind = ReportCatalog::kind($schedule->report_key);
        if ($owner === null || $member === null || ! $member->is_active || ! $this->access->can($owner, $kind)) {
            $this->record($schedule, $from, $to, 'failed', null, null, 'Pembuat jadwal tidak aktif atau tidak lagi memiliki akses laporan.');
            $schedule->forceFill([
                'is_active' => false, 'next_run_at' => null, 'last_run_at' => $now, 'last_status' => 'failed',
                'disabled_reason' => 'Dinonaktifkan otomatis: pembuat jadwal tidak lagi memiliki akses.',
            ])->save();
            $this->audit->log('report_schedule.deactivated', $schedule, new: ['is_active' => false], reason: 'Pembuat tidak lagi memiliki akses');

            return 'failed';
        }

        $file = null;
        try {
            $filter = $this->access->filter($owner, $kind, [
                'date_from' => $from->format('Y-m-d'),
                'date_to' => $to->format('Y-m-d'),
                'brand_id' => $schedule->brand_id,
                'outlet_id' => $schedule->outlet_id,
            ]);
            $table = $this->catalog->build($schedule->report_key, $filter);
            $company = Company::query()->findOrFail($schedule->company_id);
            $file = $this->exporter->export($table, $schedule->format, $company->name, $from->format('Ymd').'-'.$to->format('Ymd'));

            Mail::to($schedule->recipients)->send(new ScheduledReportMail(
                schedule: $schedule,
                table: $table,
                companyName: $company->name,
                attachmentPath: $file['path'],
                attachmentName: $file['filename'],
                mime: $file['mime'],
            ));

            $this->record($schedule, $from, $to, 'sent', $file['filename'], count($table->rows), null);
            $this->finish($schedule, 'sent', $now, $runAt);

            return 'sent';
        } catch (ModelNotFoundException) {
            $this->record($schedule, $from, $to, 'failed', null, null, 'Brand/outlet jadwal tidak lagi berada dalam cakupan pembuat.');
            $schedule->forceFill([
                'is_active' => false, 'next_run_at' => null, 'last_run_at' => $now, 'last_status' => 'failed',
                'disabled_reason' => 'Dinonaktifkan otomatis: brand/outlet tidak lagi dalam cakupan.',
            ])->save();

            return 'failed';
        } catch (Throwable $e) {
            report($e);
            $this->record($schedule, $from, $to, 'failed', $file['filename'] ?? null, null, mb_substr($e->getMessage(), 0, 300));
            $attempts = ReportDelivery::query()->where('schedule_id', $schedule->id)
                ->whereDate('period_from', $from->format('Y-m-d'))->whereDate('period_to', $to->format('Y-m-d'))
                ->where('status', 'failed')->count();
            $schedule->forceFill([
                'last_run_at' => $now,
                'last_status' => 'failed',
                // Ulang 15 menit lagi untuk periode yang sama; setelah batas, lanjut ke jadwal berikutnya.
                'next_run_at' => $attempts < self::MAX_ATTEMPTS ? $now->addMinutes(15) : $this->following($schedule, $runAt, $now),
            ])->save();

            return 'failed';
        } finally {
            if ($file !== null && is_file($file['path'])) {
                @unlink($file['path']);
            }
        }
    }

    private function finish(ReportSchedule $schedule, string $status, CarbonImmutable $now, CarbonImmutable $runAt): void
    {
        $schedule->forceFill([
            'last_run_at' => $now,
            'last_status' => $status,
            'next_run_at' => $this->following($schedule, $runAt, $now),
        ])->save();
    }

    /** Slot jadwal terakhir yang jatuh tempo pada/ sebelum waktu tertentu. */
    public function slot(ReportSchedule $schedule, CarbonImmutable $at): CarbonImmutable
    {
        $back = match ($schedule->frequency) {
            'weekly' => $at->subWeek(),
            'monthly' => $at->subMonthNoOverflow(),
            default => $at->subDay(),
        };

        return $this->nextRun($schedule, $back)->min($at);
    }

    /**
     * Slot berikutnya setelah slot yang baru diproses. Kiriman yang terlewat disusulkan satu per satu, tetapi bila
     * tertinggal lebih dari 3 hari hanya kiriman terakhir yang jatuh tempo yang dikirim.
     */
    private function following(ReportSchedule $schedule, CarbonImmutable $slot, CarbonImmutable $now): CarbonImmutable
    {
        $next = $this->nextRun($schedule, $slot);
        if ($next->lessThanOrEqualTo($now) && $next->lessThan($now->subDays(3))) {
            $latest = $this->slot($schedule, $now);

            return $latest->greaterThan($slot) ? $latest : $this->nextRun($schedule, $now);
        }

        return $next;
    }

    private function record(ReportSchedule $schedule, CarbonImmutable $from, CarbonImmutable $to, string $status, ?string $filename, ?int $rows, ?string $error): void
    {
        $delivery = new ReportDelivery;
        $delivery->forceFill([
            'id' => (string) Str::uuid7(),
            'company_id' => $schedule->company_id,
            'schedule_id' => $schedule->id,
            'report_key' => $schedule->report_key,
            'period_from' => $from->format('Y-m-d'),
            'period_to' => $to->format('Y-m-d'),
            'status' => $status,
            'recipients' => $schedule->recipients,
            'filename' => $filename,
            'row_count' => $rows,
            'error' => $error,
        ])->save();
    }

    private function companyTimezone(string $companyId): string
    {
        $tz = Company::query()->whereKey($companyId)->value('timezone');

        return is_string($tz) && $tz !== '' ? $tz : (string) config('app.display_timezone', 'Asia/Jakarta');
    }
}
