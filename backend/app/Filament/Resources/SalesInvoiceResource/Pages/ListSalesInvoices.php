<?php

namespace App\Filament\Resources\SalesInvoiceResource\Pages;

use App\Filament\Resources\SalesInvoiceResource;
use App\Modules\Treasury\Domain\Models\SalesInvoice;
use Filament\Actions\CreateAction;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSalesInvoices extends ListRecords
{
    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tagihan baru')];
    }

    /**
     * Nama parameter closure HARUS `$query` — Filament mencocokkan argumen dari namanya lebih dulu.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'semua' => Tab::make('Semua'),
            'belum_lunas' => Tab::make('Belum lunas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SalesInvoice::ISSUED))
                ->badge(fn () => SalesInvoice::query()->where('status', SalesInvoice::ISSUED)->count() ?: null),
            'jatuh_tempo' => Tab::make('Lewat jatuh tempo')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SalesInvoice::ISSUED)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now()))
                ->badge(fn () => SalesInvoice::query()->where('status', SalesInvoice::ISSUED)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count() ?: null),
            'draft' => Tab::make('Draft')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', SalesInvoice::DRAFT)),
        ];
    }
}
