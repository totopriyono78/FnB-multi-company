<?php

namespace App\Filament\Resources\ModifierGroupResource\Pages;

use App\Filament\Resources\ModifierGroupResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\ModifierGroupWriter;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditModifierGroup extends EditRecord
{
    protected static string $resource = ModifierGroupResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ModifierGroup $record */
        $record = $this->getRecord();

        return ModifierGroupResource::fillData($record, $data);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ModifierGroup $record */
        unset($data['brand_id']);

        return MenuFields::run(fn () => app(ModifierGroupWriter::class)->save($record, $data));
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }
}
