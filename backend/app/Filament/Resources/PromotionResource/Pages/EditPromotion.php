<?php

namespace App\Filament\Resources\PromotionResource\Pages;

use App\Filament\Resources\PromotionResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\PromotionWriter;
use App\Modules\Catalog\Domain\Models\Promotion;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Promotion $record */
        $record = $this->getRecord();

        return PromotionResource::fillData($record, $data);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Promotion $record */
        unset($data['brand_id']);

        return MenuFields::run(function () use ($record, $data) {
            $user = MenuFields::user() ?? abort(403);

            return app(PromotionWriter::class)->save($user, $record, PromotionResource::prepareData($data, $record));
        });
    }

    public function getTitle(): string
    {
        return 'Ubah Promo';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
