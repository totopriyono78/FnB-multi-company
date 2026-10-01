<?php

namespace App\Filament\Resources\ApprovalRuleResource\Pages;

use App\Filament\Resources\ApprovalRuleResource;
use App\Modules\Documents\Application\ApprovalMatrix;
use App\Modules\Documents\Application\DocumentException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateApprovalRule extends CreateRecord
{
    protected static string $resource = ApprovalRuleResource::class;

    /**
     * Penyimpanan lewat ApprovalMatrix::set(), bukan langsung ke model.
     *
     * Di sanalah peran diperiksa terhadap daftar peran yang dikenal, dan di sanalah baris yang sudah
     * ada untuk band + tingkat yang sama diperbarui alih-alih diduplikasi — dua baris untuk satu
     * tingkat pada satu band berarti matriksnya punya dua jawaban untuk satu pertanyaan.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ApprovalMatrix::class)->set(
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
