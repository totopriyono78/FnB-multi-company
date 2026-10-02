<?php

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Resources\GroupResource;
use App\Modules\Consolidation\Application\ConsolidationException;
use App\Modules\Consolidation\Application\GroupService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Disimpan lewat GroupService: di situlah keunikan kode diperiksa lintas tenant dan entitas holding
 * dijadikan anggota pertama grupnya sendiri — ia punya buku sendiri (talangan, beban kantor pusat)
 * yang wajib ikut dikonsolidasi.
 */
class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(GroupService::class)->create([
                'code' => (string) ($data['code'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
                'legal_name' => isset($data['legal_name']) ? (string) $data['legal_name'] : null,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            ]);
        } catch (ConsolidationException $e) {
            Notification::make()->danger()->title('Grup belum dapat dibuat')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
