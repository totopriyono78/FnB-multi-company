<?php

namespace App\Filament\Pages\Documents;

use App\Filament\Support\PaymentAccess;
use App\Modules\Documents\Application\VerificationQueue;
use App\Modules\Identity\Domain\Models\User;
use Filament\Pages\Page;

/**
 * Antrian verifikasi pusat (DOC-07).
 *
 * Satu layar untuk dibuka tiap pagi oleh orang yang menandatangani dan membukukan. Isinya bukan
 * laporan, melainkan daftar kerja: apa yang menunggu saya, apa yang tertahan, dan apa yang sudah
 * bergerak uangnya tetapi belum terbukukan.
 *
 * Tidak ada tombol tanda tangan di sini. Semua tindakan tetap di layar dokumennya sendiri, karena
 * menandatangani pengeluaran tanpa melihat keperluan, nilai, dan lampirannya adalah persetujuan yang
 * hanya terlihat seperti kontrol.
 */
class VerificationQueuePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Dokumen';

    protected static ?string $navigationLabel = 'Antrian Verifikasi';

    protected static ?string $title = 'Antrian Verifikasi';

    protected static ?string $slug = 'dokumen/antrian-verifikasi';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.documents.verification-queue';

    public static function canAccess(): bool
    {
        return PaymentAccess::canView();
    }

    /** Lencana navigasi hanya menghitung yang menunggu orang ini — bukan seluruh antrian. */
    public static function getNavigationBadge(): ?string
    {
        $user = PaymentAccess::user();
        if ($user === null || ! PaymentAccess::canView()) {
            return null;
        }
        $queue = app(VerificationQueue::class);
        $jumlah = count($queue->awaitingMySignature($user)) + count($queue->journalsAwaitingPosting($user));

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function queue(): VerificationQueue
    {
        return app(VerificationQueue::class);
    }

    public function viewer(): ?User
    {
        return PaymentAccess::user();
    }
}
