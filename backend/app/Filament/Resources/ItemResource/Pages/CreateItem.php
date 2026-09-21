<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Application\MenuScope;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateItem extends CreateRecord
{
    protected static string $resource = ItemResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return MenuFields::run(function () use ($data) {
            $user = MenuFields::user();
            if ($user === null || ! app(MenuScope::class)->canManageBrand($user, (string) $data['brand_id'])) {
                throw new AuthorizationException('Anda tidak mengelola menu brand ini.');
            }

            return app(ItemWriter::class)->save(null, ItemResource::prepareData($data));
        });
    }

    public function getTitle(): string
    {
        return 'Tambah Menu';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Menu');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
