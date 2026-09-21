<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Inventory\Application\StockDocumentService;
use App\Modules\Inventory\Domain\Models\StockTransfer;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    /** Relasi dimuat ulang di setiap request Livewire (model dihidrasi ulang tanpa relasi). */
    public function booted(): void
    {
        $this->transfer()->loadMissing(['lines.ingredient', 'fromLocation.outlet', 'toLocation.outlet']);
    }

    public function getTitle(): string
    {
        return 'Transfer '.$this->transfer()->number;
    }

    private function transfer(): StockTransfer
    {
        /** @var StockTransfer $record */
        $record = $this->getRecord();

        return $record;
    }

    private function can(string $side): bool
    {
        $user = InventoryFields::user();
        $transfer = $this->transfer();
        $location = $side === 'to' ? $transfer->toLocation : $transfer->fromLocation;

        return $user !== null && $transfer->status === StockTransfer::IN_TRANSIT && InventoryFields::access()->canManageOutlet($user, $location->outlet);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive')->label('Terima Barang')->icon('heroicon-m-inbox-arrow-down')->color('success')
                ->visible(fn () => $this->can('to'))
                ->modalHeading('Terima transfer')
                ->modalDescription('Isi jumlah yang benar-benar diterima. Selisih dengan jumlah kirim tercatat sebagai kehilangan di perjalanan.')
                ->fillForm(fn () => ['lines' => $this->transfer()->lines->map(fn ($l) => [
                    'line_id' => $l->id,
                    'label' => $l->ingredient->name.' — dikirim '.InventoryFields::qtyWithUnit((string) $l->qty_sent, $l->ingredient->base_unit),
                    'qty_received' => InventoryFields::plain((string) $l->qty_sent),
                ])->all()])
                ->form([
                    Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)
                        ->schema([
                            Hidden::make('line_id'),
                            Placeholder::make('label')->hiddenLabel()->content(fn ($get) => $get('label')),
                            InventoryFields::qty('qty_received', 'Jumlah diterima'),
                        ])->columns(2),
                    Textarea::make('note')->label('Catatan penerimaan')->rows(2)->maxLength(300),
                ])
                ->modalSubmitActionLabel('Simpan Penerimaan')
                ->action(function (array $data): void {
                    $this->runAction(fn ($user) => app(StockDocumentService::class)->receive($user, $this->transfer(), [
                        'note' => $data['note'] ?? null,
                        'lines' => array_values(array_map(fn (array $l) => ['line_id' => (string) $l['line_id'], 'qty_received' => (string) $l['qty_received']], $data['lines'] ?? [])),
                    ]), 'Transfer diterima. Stok tujuan sudah bertambah.');
                }),
            Action::make('cancel')->label('Batalkan Transfer')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn () => $this->can('from'))
                ->requiresConfirmation()
                ->modalHeading('Batalkan transfer?')
                ->modalDescription('Stok dikembalikan ke lokasi asal.')
                ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Ya, batalkan')
                ->action(function (array $data): void {
                    $this->runAction(fn ($user) => app(StockDocumentService::class)->cancelTransfer($user, $this->transfer(), (string) $data['reason']), 'Transfer dibatalkan.');
                }),
        ];
    }

    /** @param  \Closure(User): mixed  $action */
    private function runAction(\Closure $action, string $message): void
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
        $this->redirect(StockTransferResource::getUrl('view', ['record' => $this->transfer()]));
    }
}
