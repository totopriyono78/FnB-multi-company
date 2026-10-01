<?php

namespace Database\Seeders;

use App\Modules\Accounting\Application\ChartOfAccounts;
use App\Modules\Accounting\Domain\Models\Account;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\PaymentAdviceService;
use App\Modules\Documents\Application\PaymentRequestService;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Dokumen pembayaran untuk peragaan (Kelompok 4).
 *
 * Yang dibuat bukan satu contoh, melainkan satu antrian: pengajuan yang masih draft, yang menunggu
 * tanda tangan tingkat dua, yang sudah disetujui tetapi belum dibayar, dan yang sudah dibayar
 * sebagian. Antrian verifikasi baru bisa dinilai kalau isinya memang bermacam-macam — layar yang
 * hanya pernah dilihat dengan satu dokumen ideal selalu tampak baik-baik saja.
 */
class DemoDocumentSeeder extends Seeder
{
    public function run(): void
    {
        // Idempoten: seeder demo dijalankan berulang kali di basis data yang sama.
        if (PaymentRequest::query()->exists()) {
            return;
        }
        if (Account::query()->doesntExist()) {
            app(ChartOfAccounts::class)->installTemplate();
        }
        app(ApprovalMatrix::class)->installDefaults();

        $manajer = $this->user('dewi@gtgroup.test');
        $finance = $this->user('lina@gtgroup.test');
        $admin = $this->user('bayu@gtgroup.test');
        if ($manajer === null || $finance === null || $admin === null) {
            return;
        }

        $outlet = Outlet::query()->where('code', 'KLU')->value('id');
        $beban = Account::query()->where('code', '6108')->value('id');
        $bank = Account::query()->where('code', '1110')->value('id');
        if (! is_string($beban) || ! is_string($bank)) {
            return;
        }

        $hariIni = CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
        $requests = app(PaymentRequestService::class);

        // 1. Masih draft — pengajuan yang baru diketik manajer outlet.
        $requests->create([
            'request_date' => $hariIni->format('Y-m-d'),
            'outlet_id' => $outlet, 'payee_name' => 'CV Teknik Dingin Sejahtera',
            'amount' => '3200000', 'expense_account_id' => $beban,
            'description' => 'Perbaikan chiller dan penggantian kompresor',
        ], $manajer);

        // 2. Menunggu tanda tangan tingkat 2 — nilainya masuk band 50 juta, finance sudah tanda tangan.
        $renovasi = $requests->create([
            'request_date' => $hariIni->subDays(5)->format('Y-m-d'),
            'due_date' => $hariIni->addDays(9)->format('Y-m-d'),
            'outlet_id' => $outlet, 'payee_name' => 'PT Karya Interior Nusantara',
            'amount' => '28500000', 'expense_account_id' => $beban,
            'description' => 'Renovasi area outdoor dan penggantian kanopi',
        ], $manajer);
        $renovasi = $requests->submit($renovasi, $manajer);
        $requests->approve($renovasi, $finance, 'Penawaran sudah dibandingkan dengan dua vendor.');

        // 3. Disetujui lengkap, belum dibayar, jatuh temponya sudah lewat — inilah yang harus menonjol
        //    di antrian: bukan dokumen yang ditolak, tetapi yang disetujui lalu dilupakan.
        $iklan = $requests->create([
            'request_date' => $hariIni->subDays(10)->format('Y-m-d'),
            'due_date' => $hariIni->subDays(2)->format('Y-m-d'),
            'payee_name' => 'Kreatif Digital Yogyakarta',
            'amount' => '4500000', 'expense_account_id' => $beban,
            'description' => 'Produksi konten media sosial periode berjalan',
        ], $manajer);
        $requests->approve($requests->submit($iklan, $manajer), $finance);

        // 4. Dibayar sebagian — advis bayar pertama sudah terbit, jurnalnya masih draft.
        $katering = $requests->create([
            'request_date' => $hariIni->subDays(7)->format('Y-m-d'),
            'outlet_id' => $outlet, 'payee_name' => 'Dapur Bu Tini',
            'amount' => '6000000', 'expense_account_id' => $beban,
            'description' => 'Konsumsi pelatihan barista dua angkatan',
        ], $manajer);
        // Rp6 juta sudah melewati band 5 juta, jadi butuh dua tanda tangan — bukan satu.
        $katering = $requests->approve($requests->submit($katering, $manajer), $finance);
        $katering = $requests->approve($katering, $admin);
        app(PaymentAdviceService::class)->issue($katering, [
            'paid_on' => $hariIni->subDays(3)->format('Y-m-d'),
            'amount' => '3000000', 'bank_account_id' => $bank,
            'reference' => 'TRF/0919/0042', 'note' => 'Termin pertama, sisanya setelah angkatan kedua.',
        ], $finance);
    }

    private function user(string $email): ?User
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        return $user;
    }
}
