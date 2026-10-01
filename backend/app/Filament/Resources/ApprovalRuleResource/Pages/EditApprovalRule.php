<?php

namespace App\Filament\Resources\ApprovalRuleResource\Pages;

use App\Filament\Resources\ApprovalRuleResource;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\DocumentException;
use App\Modules\Documents\Domain\Models\ApprovalRule;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditApprovalRule extends EditRecord
{
    protected static string $resource = ApprovalRuleResource::class;

    /**
     * Baris dipindahkan di tempat, bukan ditulis ulang lewat `set()`: `set()` mengenali baris dari
     * band + tingkatnya, sehingga mengubah tingkat akan meninggalkan baris lamanya hidup.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof ApprovalRule) {
            throw new Halt;
        }

        try {
            return app(ApprovalMatrix::class)->move(
                $record,
                (string) $data['doc_type'],
                ApprovalRuleResource::band($data),
                (int) $data['level'],
                (string) $data['role'],
            );
        } catch (DocumentException $e) {
            Notification::make()->danger()->title('Tidak dapat disimpan')->body($e->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
