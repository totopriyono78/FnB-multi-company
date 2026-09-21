<?php

namespace App\Filament\Resources\MenuCategoryResource\Pages;

use App\Filament\Resources\MenuCategoryResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateMenuCategory extends CreateRecord
{
    protected static string $resource = MenuCategoryResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Tambah Kategori';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Kategori');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
