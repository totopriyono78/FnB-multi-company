<?php

namespace App\Filament\Resources\PaymentRequestResource\Pages;

use App\Filament\Resources\PaymentRequestResource;
use App\Modules\Documents\Domain\Models\PaymentRequest;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Pengajuan baru')];
    }

    /**
     * Tab, bukan hanya penyaring status: pertanyaan "mana yang masih menunggu saya" dan "mana yang
     * sudah disetujui tetapi belum dibayar" diajukan berkali-kali sehari, dan keduanya tidak boleh
     * menuntut orang membuka menu penyaring lebih dulu.
     *
     * Nama parameter closure di bawah HARUS `$query`. Filament mencocokkan argumen closure dari
     * namanya lebih dulu; dengan nama lain (`$q`, misalnya) ia tidak menemukan apa pun dan membuat
     * sendiri sebuah Builder dari container — Builder tanpa model. Kueri tanpa model itu kemudian
     * diteruskan ke penyaring tabel, dan galatnya muncul jauh dari sebabnya: "Call to a member
     * function approvals() on null" di dalam modifyQueryUsing milik resource-nya.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            /*
             * "Semua" lebih dulu, dan karena itu menjadi tab bawaan. Tab penyaring di posisi pertama
             * terasa pintar sampai seseorang membuat draft dan draftnya langsung hilang dari layar —
             * daftar yang tidak memuat dokumen yang baru saja dibuat orangnya terbaca sebagai gagal
             * menyimpan, bukan sebagai penyaringan.
             */
            'semua' => Tab::make('Semua'),
            'menunggu' => Tab::make('Menunggu persetujuan')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentRequest::SUBMITTED))
                ->badge(fn () => PaymentRequest::query()->where('status', PaymentRequest::SUBMITTED)->count() ?: null),
            'belum_dibayar' => Tab::make('Disetujui, belum lunas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentRequest::APPROVED)
                    ->whereColumn('paid_amount', '<', 'amount'))
                ->badge(fn () => PaymentRequest::query()->where('status', PaymentRequest::APPROVED)
                    ->whereColumn('paid_amount', '<', 'amount')->count() ?: null),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentRequest::DRAFT)),
        ];
    }
}
