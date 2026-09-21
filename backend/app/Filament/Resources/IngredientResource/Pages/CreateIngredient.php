<?php

namespace App\Filament\Resources\IngredientResource\Pages;

use App\Filament\Resources\IngredientResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateIngredient extends CreateRecord
{
    protected static string $resource = IngredientResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Tambah Bahan';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Bahan');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
