<?php

namespace App\Filament\Resources\MenuCategoryResource\Pages;

use App\Filament\Resources\MenuCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMenuCategories extends ListRecords
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tambah Kategori')];
    }
}
