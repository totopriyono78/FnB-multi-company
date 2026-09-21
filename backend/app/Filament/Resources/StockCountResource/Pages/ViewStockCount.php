<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\StockCountService;
use App\Modules\Inventory\Domain\Models\StockCount;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    /** Relasi dimuat ulang di setiap request Livewire (model dihidrasi ulang tanpa relasi). */
    public function booted(): void
    {
        $this->count()->loadMissing(['lines.ingredient', 'location.outlet']);
    }

    public function getTitle(): string
    {
        return 'Opname '.$this->count()->number;
    }

    private function count(): StockCount
    {
        /** @var StockCount $record */
        $record = $this->getRecord();

        return $record;
    }

    private function canManage(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && InventoryFields::access()->canManageOutlet($user, $this->count()->location->outlet);
    }

    private function canApprove(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && $this->count()->status === StockCount::SUBMITTED
            && InventoryFields::access()->canApproveCount($user, $this->count()->location->outlet);
    }

    protected function getHeaderActions(): array
    {
        $counting = fn () => $this->count()->status === StockCount::COUNTING && $this->canManage();

        return [
            Action::make('record')->label('Isi Hasil Hitung')->icon('heroicon-m-pencil-square')
                ->visible($counting)
                ->modalHeading('Isi jumlah fisik')
                ->modalDescription('Hitung tanpa melihat saldo sistem. Kosongkan bahan yang belum dihitung.')
                ->modalWidth('3xl')
                ->fillForm(fn () => ['lines' => $this->count()->lines->sortBy(fn ($l) => $l->ingredient->name)->map(fn ($l) => [
                    'ingredient_id' => $l->ingredient_id,
                    'label' => $l->ingredient->name.' ('.$l->ingredient->base_unit.')',
                    'counted_qty' => InventoryFields::plain($l->counted_qty === null ? null : (string) $l->counted_qty),
                    'note' => $l->note,
                ])->values()->all()])
                ->form([
                    Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(3)
                        ->schema([
                            Hidden::make('ingredient_id'),
                            Placeholder::make('label')->hiddenLabel()->content(fn ($get) => $get('label')),
                            InventoryFields::qty('counted_qty', 'Jumlah fisik')->required(false)->rules(['nullable']),
                            TextInput::make('note')->label('Catatan')->maxLength(200),
                        ]),
                ])
                ->modalSubmitActionLabel('Simpan Hasil Hitung')
                ->action(fn (array $data) => $this->run(fn (User $user) => app(StockCountService::class)->record($user, $this->count(), array_values(array_map(fn (array $l) => [
                    'ingredient_id' => (string) $l['ingredient_id'],
                    'counted_qty' => ($l['counted_qty'] ?? '') === '' ? null : (string) $l['counted_qty'],
                    'note' => $l['note'] ?? null,
                ], $data['lines'] ?? []))), 'Hasil hitung disimpan.')),
            Action::make('submit')->label('Ajukan ke Manajer')->icon('heroicon-m-paper-airplane')->color('success')
                ->visible($counting)
                ->requiresConfirmation()
                ->modalHeading('Ajukan hasil opname?')
                ->modalDescription('Hasil hitung tidak dapat diubah setelah diajukan, kecuali manajer meminta hitung ulang.')
                ->modalSubmitActionLabel('Ya, ajukan')
                ->action(fn () => $this->run(fn (User $user) => app(StockCountService::class)->submit($user, $this->count()), 'Opname diajukan untuk disetujui.')),
            Action::make('approve')->label('Setujui & Sesuaikan Stok')->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => $this->canApprove())
                ->requiresConfirmation()
                ->modalHeading('Setujui hasil opname?')
                ->modalDescription(fn () => 'Selisih senilai '.MenuFields::rupiah((string) $this->count()->variance_value).' akan diposting ke stok.')
                ->form([Textarea::make('note')->label('Catatan (opsional)')->rows(2)->maxLength(300)])
                ->modalSubmitActionLabel('Ya, setujui')
                ->action(fn (array $data) => $this->run(fn (User $user) => app(StockCountService::class)->approve($user, $this->count(), $data['note'] ?? null), 'Opname disetujui. Stok sudah disesuaikan.')),
            Action::make('recount')->label('Minta Hitung Ulang')->icon('heroicon-m-arrow-uturn-left')->color('warning')
                ->visible(fn () => $this->canApprove())
                ->form([Textarea::make('note')->label('Bahan yang perlu dicek')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Kirim Permintaan')
                ->action(fn (array $data) => $this->run(fn (User $user) => app(StockCountService::class)->requestRecount($user, $this->count(), (string) $data['note']), 'Opname dikembalikan untuk dihitung ulang.')),
            Action::make('cancel')->label('Batalkan')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn () => in_array($this->count()->status, [StockCount::COUNTING, StockCount::SUBMITTED], true) && $this->canManage())
                ->requiresConfirmation()
                ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Ya, batalkan')
                ->action(fn (array $data) => $this->run(fn (User $user) => app(StockCountService::class)->cancel($user, $this->count(), (string) $data['reason']), 'Opname dibatalkan.')),
        ];
    }

    /** @param  \Closure(User): mixed  $action */
    private function run(\Closure $action, string $message): void
    {
        $user = InventoryFields::user();
        if ($user === null) {
            return;
        }
        try {
            MenuFields::run(fn () => $action($user));
        } catch (Halt) {
            return;
        }
        Notification::make()->success()->title($message)->send();
        $this->redirect(StockCountResource::getUrl('view', ['record' => $this->count()]));
    }
}
