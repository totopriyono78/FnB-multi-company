<?php

namespace App\Filament\Resources\OutletResource\RelationManagers;

use App\Filament\Support\MenuFields;
use App\Filament\Tables\Columns\LabeledToggleColumn;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Payment\Application\PaymentMethods;
use App\Modules\Payment\Domain\Models\OutletPaymentMethod;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Metode pembayaran aktif, urutan, dan MDR per outlet (FR-PAY-02, FR-PAY-10). */
class PaymentMethodsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentMethods';

    protected static ?string $title = 'Metode Pembayaran';

    protected static ?string $modelLabel = 'metode pembayaran';

    /** Tabel kecil (6 baris): tampilkan langsung tanpa menunggu digulir. */
    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Outlet && (MenuFields::user()?->can('view', $ownerRecord) ?? false);
    }

    public function mount(): void
    {
        parent::mount();
        // Outlet lama (sebelum Tahap 3) dilengkapi hanya oleh pengguna yang boleh mengubah.
        if ($this->canManage()) {
            app(PaymentMethods::class)->ensure($this->outlet());
        }
    }

    private function outlet(): Outlet
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Outlet ? $owner : abort(404);
    }

    private function canManage(): bool
    {
        return WritableCompany::allows() && (MenuFields::user()?->can('update', $this->outlet()) ?? false);
    }

    protected function canEdit(Model $record): bool
    {
        return $this->canManage();
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            TextInput::make('label')->label('Nama di kasir')->required()->maxLength(40),
            TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(999)->required(),
            TextInput::make('mdr_percent')->label('MDR (%)')->suffix('%')->inputMode('decimal')
                ->rules(['decimal:0,2', 'min:0', 'max:100'])->required(),
            MenuFields::money('mdr_fixed', 'MDR tetap per transaksi')->rules(['max:1000000']),
            Toggle::make('is_active')->label('Aktif di kasir'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('label')->label('Metode'),
                TextColumn::make('method')->label('Kode')->fontFamily('mono'),
                TextColumn::make('via_gateway')->label('Jalur')
                    ->getStateUsing(fn (OutletPaymentMethod $r) => in_array($r->method, PaymentMethods::GATEWAY_METHODS, true) ? 'Payment gateway' : 'Langsung di kasir'),
                LabeledToggleColumn::make('is_active')->label('Aktif')
                    ->switchLabel(fn (OutletPaymentMethod $r) => 'Aktifkan '.$r->label)
                    ->disabled(fn () => ! $this->canManage())
                    ->beforeStateUpdated(function (OutletPaymentMethod $record, bool $state): void {
                        $this->guardActive($record, $state);
                    })
                    ->afterStateUpdated(fn (OutletPaymentMethod $record, bool $state) => $this->audit($record, ['is_active' => ! $state], ['is_active' => $state])),
                TextColumn::make('sort_order')->label('Urutan')->alignEnd(),
                TextColumn::make('mdr_percent')->label('MDR')->alignEnd()
                    ->formatStateUsing(fn ($state, OutletPaymentMethod $r) => rtrim(rtrim((string) $state, '0'), '.').'%'.((float) $r->mdr_fixed > 0 ? ' + '.MenuFields::rupiah((string) $r->mdr_fixed) : '')),
            ])
            ->actions([
                EditAction::make()->label('Ubah')
                    ->using(function (OutletPaymentMethod $record, array $data): OutletPaymentMethod {
                        if (! $this->canManage()) {
                            Notification::make()->danger()->title('Anda tidak berwenang mengubah metode pembayaran.')->send();
                            throw new Halt;
                        }
                        $this->guardActive($record, (bool) ($data['is_active'] ?? false));
                        $before = $record->only(['label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed']);
                        $record->fill(array_intersect_key($data, array_flip(['label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed'])));
                        if ($record->isDirty()) {
                            $record->save();
                            $this->audit($record, $before, $record->only(['label', 'is_active', 'sort_order', 'mdr_percent', 'mdr_fixed']));
                        }

                        return $record;
                    }),
            ]);
    }

    private function guardActive(OutletPaymentMethod $record, bool $active): void
    {
        if (! $this->canManage()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah metode pembayaran.')->send();
            throw new Halt;
        }
        if (! $active && ! OutletPaymentMethod::query()->where('outlet_id', $record->outlet_id)->where('is_active', true)->whereKeyNot($record->id)->exists()) {
            Notification::make()->danger()->title('Minimal satu metode pembayaran harus aktif.')->send();
            throw new Halt;
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(OutletPaymentMethod $record, array $before, array $after): void
    {
        app(AuditLogger::class)->log('outlet.payment_method_updated', $this->outlet(), $before, $after, metadata: ['method' => $record->method]);
    }
}
