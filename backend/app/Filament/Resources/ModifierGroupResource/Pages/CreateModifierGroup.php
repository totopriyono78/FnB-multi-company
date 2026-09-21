<?php

namespace App\Filament\Resources\ModifierGroupResource\Pages;

use App\Filament\Resources\ModifierGroupResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\ModifierGroupWriter;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateModifierGroup extends CreateRecord
{
    protected static string $resource = ModifierGroupResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return MenuFields::run(fn () => app(ModifierGroupWriter::class)->save(new ModifierGroup, $data));
    }

    public function getTitle(): string
    {
        return 'Tambah Grup Modifier';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Grup');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
