<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\GroupService;
use App\Modules\Consolidation\Domain\Models\Group;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Group) {
            throw new Halt;
        }
        try {
            return app(GroupService::class)->update($record, $data);
        } catch (ConsolidationException $e) {
            Notification::make()->danger()->title('Perubahan belum dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
