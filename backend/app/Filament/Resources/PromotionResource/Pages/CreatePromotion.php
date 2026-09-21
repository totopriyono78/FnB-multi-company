<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\PromotionWriter;
use App\Modules\Catalog\Domain\Models\Promotion;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        return MenuFields::run(function () use ($data) {
            $user = MenuFields::user() ?? abort(403);
            $data['brand_id'] = $data['brand_id'] ?? null;

            return app(PromotionWriter::class)->save($user, new Promotion, PromotionResource::prepareData($data));
        });
    }

    public function getTitle(): string
    {
        return 'Buat Promo';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Simpan Promo');
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
