<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\ItemWriter;
use App\Modules\Catalog\Domain\Models\Item;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditItem extends EditRecord
{
    protected static string $resource = ItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Item $record */
        $record = $this->getRecord();

        return ItemResource::fillData($record, $data);
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Item $record */
        unset($data['brand_id']);
        $data['type'] = $record->type;

        return MenuFields::run(fn () => app(ItemWriter::class)->save($record, ItemResource::prepareData($data)));
    }

    public function getTitle(): string
    {
        /** @var Item $record */
        $record = $this->getRecord();

        return 'Ubah Menu: '.$record->name;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Perubahan');
    }

    public function getRelationManagersContentTabLabel(): ?string
    {
        return 'Menu';
    }
}
