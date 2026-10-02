<?php

namespace App\Filament\Resources\PurchaseInvoiceResource\Pages;

use App\Filament\Resources\PurchaseInvoiceResource;
use App\Modules\Treasury\Domain\Models\PurchaseInvoice;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPurchaseInvoices extends ListRecords
{
    protected static string $resource = PurchaseInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Faktur baru')];
    }

    /**
     * Nama parameter closure HARUS `$query` — Filament mencocokkan argumen dari namanya lebih dulu,
     * dan dengan nama lain ia membuat sendiri Builder tanpa model dari container.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            // "Semua" lebih dulu, dan karena itu menjadi tab bawaan: draft yang baru dibuat tidak
            // boleh langsung hilang dari layar orang yang baru saja membuatnya.
            'semua' => Tab::make('Semua'),
            'belum_lunas' => Tab::make('Belum lunas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PurchaseInvoice::ISSUED))
                ->badge(fn () => PurchaseInvoice::query()->where('status', PurchaseInvoice::ISSUED)->count() ?: null),
            'jatuh_tempo' => Tab::make('Lewat jatuh tempo')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PurchaseInvoice::ISSUED)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now()))
                ->badge(fn () => PurchaseInvoice::query()->where('status', PurchaseInvoice::ISSUED)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count() ?: null),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PurchaseInvoice::DRAFT)),
        ];
    }
}
