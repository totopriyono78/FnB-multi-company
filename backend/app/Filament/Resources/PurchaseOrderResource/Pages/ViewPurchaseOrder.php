<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Purchasing\Application\GoodsReceiptService;
use App\Modules\Purchasing\Application\PurchaseOrderService;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    /** Relasi dimuat ulang di setiap request Livewire (model dihidrasi ulang tanpa relasi). */
    public function booted(): void
    {
        $this->po()->loadMissing(['lines.ingredient', 'supplier', 'location.outlet', 'creator']);
    }

    public function getTitle(): string
    {
        return 'PO '.$this->po()->number;
    }

    private function po(): PurchaseOrder
    {
        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        return $record;
    }

    private function user(): ?User
    {
        return InventoryFields::user();
    }

    protected function getHeaderActions(): array
    {
        $service = app(PurchaseOrderService::class);
        $access = InventoryFields::access();
        $status = fn (string ...$in) => in_array($this->po()->status, $in, true);
        $requester = fn () => ($u = $this->user()) !== null && $service->canRequesterAct($u, $this->po());
        $approver = fn () => ($u = $this->user()) !== null && $access->canApprovePurchase($u, $this->po()->location->outlet);
        $receiver = fn () => ($u = $this->user()) !== null && $access->canReceive($u, $this->po()->location->outlet);
        $manager = fn () => ($u = $this->user()) !== null && $access->canManagePurchase($u, $this->po()->location->outlet);

        return [
            EditAction::make()->label('Ubah Draf')->visible(fn () => $status(PurchaseOrder::DRAFT) && $requester()),
            Action::make('submit')->label('Ajukan Persetujuan')->icon('heroicon-m-paper-airplane')
                ->visible(fn () => $status(PurchaseOrder::DRAFT) && $requester())
                ->requiresConfirmation()->modalHeading('Ajukan PO untuk disetujui?')->modalSubmitActionLabel('Ya, ajukan')
                ->action(fn () => $this->run(fn (User $u) => $service->submit($u, $this->po()), 'PO diajukan.')),
            Action::make('approve')->label('Setujui')->icon('heroicon-m-check')->color('success')
                ->visible(fn () => $status(PurchaseOrder::SUBMITTED) && $approver())
                ->form([Textarea::make('note')->label('Catatan (opsional)')->rows(2)->maxLength(300)])
                ->modalHeading('Setujui PO?')->modalDescription(fn () => 'Total '.MenuFields::rupiah((string) $this->po()->total).' ke '.$this->po()->supplier->name.'.')
                ->modalSubmitActionLabel('Ya, setujui')
                ->action(fn (array $data) => $this->run(fn (User $u) => $service->approve($u, $this->po(), $data['note'] ?? null), 'PO disetujui.')),
            Action::make('reject')->label('Tolak')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn () => $status(PurchaseOrder::SUBMITTED) && $approver())
                ->form([Textarea::make('note')->label('Alasan penolakan')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Tolak PO')
                ->action(fn (array $data) => $this->run(fn (User $u) => $service->reject($u, $this->po(), (string) $data['note']), 'PO ditolak.')),
            Action::make('receive')->label('Terima Barang')->icon('heroicon-m-inbox-arrow-down')->color('success')
                ->visible(fn () => $status(...PurchaseOrder::RECEIVABLE) && $receiver())
                ->modalHeading('Terima barang dari pemasok')
                ->modalDescription('Isi jumlah yang datang. Ubah harga bila berbeda dengan faktur pemasok.')
                ->modalWidth('3xl')
                ->fillForm(fn () => ['lines' => $this->po()->lines->map(function ($l) {
                    $rest = BigDecimal::of((string) $l->qty)->minus((string) $l->received_qty);

                    return [
                        'purchase_order_line_id' => $l->id,
                        'label' => $l->ingredient->name.' — sisa '.InventoryFields::qtyWithUnit((string) $rest, $l->unit_name),
                        'qty' => InventoryFields::plain((string) $rest),
                        'unit_price' => MenuFields::plain((string) $l->unit_price),
                    ];
                })->all()])
                ->form([
                    TextInput::make('supplier_invoice_no')->label('Nomor faktur / surat jalan')->maxLength(60),
                    Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(3)
                        ->schema([
                            Hidden::make('purchase_order_line_id'),
                            Placeholder::make('label')->hiddenLabel()->content(fn ($get) => $get('label')),
                            InventoryFields::qty('qty', 'Jumlah diterima'),
                            MenuFields::money('unit_price', 'Harga / satuan'),
                        ]),
                    Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300),
                ])
                ->modalSubmitActionLabel('Simpan Penerimaan')
                ->action(function (array $data): void {
                    $this->run(fn (User $u) => app(GoodsReceiptService::class)->fromPurchaseOrder($u, $this->po(), [
                        'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'lines' => array_values(array_map(fn (array $l) => [
                            'purchase_order_line_id' => (string) $l['purchase_order_line_id'],
                            'qty' => (string) $l['qty'],
                            'unit_price' => (string) $l['unit_price'],
                        ], $data['lines'] ?? [])),
                    ]), 'Barang diterima. Stok dan HPP sudah diperbarui.');
                }),
            Action::make('receipts')->label('Riwayat Penerimaan')->icon('heroicon-o-inbox-stack')->color('gray')
                ->visible(fn () => $status(PurchaseOrder::PARTIALLY_RECEIVED, PurchaseOrder::RECEIVED, PurchaseOrder::CLOSED))
                ->url(fn () => GoodsReceiptResource::getUrl('index', ['tableFilters' => ['purchase_order_id' => ['value' => $this->po()->id]]])),
            Action::make('close')->label('Tutup PO')->icon('heroicon-m-lock-closed')->color('gray')
                ->visible(fn () => $status(PurchaseOrder::PARTIALLY_RECEIVED) && $manager())
                ->form([TextInput::make('reason')->label('Alasan (sisa tidak dikirim)')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Tutup PO')
                ->action(fn (array $data) => $this->run(fn (User $u) => $service->close($u, $this->po(), (string) $data['reason']), 'PO ditutup.')),
            Action::make('cancel')->label('Batalkan PO')->icon('heroicon-m-trash')->color('danger')
                ->visible(fn () => ($status(PurchaseOrder::DRAFT, PurchaseOrder::SUBMITTED) && $requester()) || ($status(PurchaseOrder::APPROVED) && $manager()))
                ->requiresConfirmation()
                ->form([TextInput::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(300)])
                ->modalSubmitActionLabel('Ya, batalkan')
                ->action(fn (array $data) => $this->run(fn (User $u) => $service->cancel($u, $this->po(), (string) $data['reason']), 'PO dibatalkan.')),
        ];
    }

    /** @param  \Closure(User): mixed  $action */
    private function run(\Closure $action, string $message): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }
        try {
            MenuFields::run(fn () => $action($user));
        } catch (Halt) {
            return;
        }
        Notification::make()->success()->title($message)->send();
        $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $this->po()]));
    }
}
