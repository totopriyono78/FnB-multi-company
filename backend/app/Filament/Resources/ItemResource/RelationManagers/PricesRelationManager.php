<?php

namespace App\Filament\Resources\ItemResource\RelationManagers;

use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemPrice;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Tenancy\Application\WritableCompany;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/** Harga khusus per outlet dan/atau channel (FR-MENU-06, FR-MENU-07). */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Harga Khusus';

    protected static ?string $modelLabel = 'harga khusus';

    private function item(): Item
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof Item ? $owner : abort(404);
    }

    /**
     * Pemeriksaan ulang di server: varian milik menu ini, outlet di brand & cakupan user, channel milik company.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(array $data): array
    {
        $item = $this->item();
        if (! $this->canManage()
            || (! empty($data['item_variant_id']) && ! $item->variants()->whereKey($data['item_variant_id'])->exists())
            || (! empty($data['outlet_id']) && ! array_key_exists((string) $data['outlet_id'], MenuFields::outlets($item->brand_id)))
            || (! empty($data['sales_channel_id']) && ! SalesChannel::query()->whereKey($data['sales_channel_id'])->exists())
            || (empty($data['outlet_id']) && empty($data['sales_channel_id']))) {
            Notification::make()->danger()->title('Data harga tidak valid.')->send();
            throw new Halt;
        }

        return Arr::only($data, ['item_variant_id', 'outlet_id', 'sales_channel_id', 'price']);
    }

    /**
     * Pengguna yang dibatasi outlet hanya melihat harga umum dan harga outletnya.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function visibleOutlets(Builder $query): Builder
    {
        $user = MenuFields::user();
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        if (app(AccessScope::class)->isCompanyWide($user)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', array_keys(MenuFields::outlets($this->item()->brand_id))));
    }

    public function isReadOnly(): bool
    {
        return ! $this->canManage();
    }

    private function canManage(): bool
    {
        $item = $this->item();
        $user = MenuFields::user();

        return $user !== null && WritableCompany::allows() && app(MenuScope::class)->canManageBrand($user, $item->brand_id);
    }

    public function form(Form $form): Form
    {
        $item = $this->item();

        return $form->schema([
            Select::make('item_variant_id')->label('Varian')
                ->options($item->variants()->pluck('name', 'id')->all())
                ->in(fn () => $item->variants()->pluck('id')->all())
                ->visible($item->variants()->exists())
                ->required($item->variants()->exists()),
            Select::make('outlet_id')->label('Outlet')
                ->options(fn () => MenuFields::outlets($item->brand_id))
                ->in(fn () => array_keys(MenuFields::outlets($item->brand_id)))
                ->placeholder('Semua outlet')
                ->requiredWithout('sales_channel_id')
                ->validationMessages(['required_without' => 'Pilih outlet atau channel.']),
            Select::make('sales_channel_id')->label('Channel')
                ->options(fn () => SalesChannel::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                ->in(fn () => SalesChannel::query()->pluck('id')->all())
                ->placeholder('Semua channel'),
            // Aturan kombinasi dipasang di harga karena field outlet/channel boleh kosong (aturan tidak dijalankan untuk nilai kosong).
            MenuFields::money('price', 'Harga')
                ->rule(fn (Get $get, ?ItemPrice $record) => $this->uniqueCombination($get, $record)),
        ])->columns(2);
    }

    private function uniqueCombination(Get $get, ?ItemPrice $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $exists = ItemPrice::query()
                ->where('item_id', $this->getOwnerRecord()->getKey())
                ->where(fn (Builder $q) => $get('item_variant_id') ? $q->where('item_variant_id', $get('item_variant_id')) : $q->whereNull('item_variant_id'))
                ->where(fn (Builder $q) => $get('outlet_id') ? $q->where('outlet_id', $get('outlet_id')) : $q->whereNull('outlet_id'))
                ->where(fn (Builder $q) => $get('sales_channel_id') ? $q->where('sales_channel_id', $get('sales_channel_id')) : $q->whereNull('sales_channel_id'))
                ->when($record !== null, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                ->exists();
            if ($exists) {
                $fail('Harga untuk kombinasi varian, outlet, dan channel ini sudah ada.');
            }
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->visibleOutlets($query)->with(['variant', 'outlet', 'channel']))
            ->columns([
                TextColumn::make('variant.name')->label('Varian')->placeholder('-'),
                TextColumn::make('outlet.name')->label('Outlet')->placeholder('Semua outlet'),
                TextColumn::make('channel.name')->label('Channel')->placeholder('Semua channel'),
                TextColumn::make('price')->label('Harga')->alignEnd()
                    ->formatStateUsing(fn ($state) => MenuFields::rupiah((string) $state)),
                TextColumn::make('updated_at')->label('Diubah')->since(),
            ])
            ->headerActions([
                CreateAction::make()->label('Tambah Harga Khusus')->visible(fn () => $this->canManage())
                    ->using(fn (array $data): Model => $this->item()->prices()->create($this->checked($data))),
            ])
            ->actions([
                EditAction::make()->label('Ubah')->visible(fn () => $this->canManage())
                    ->using(function (ItemPrice $record, array $data): ItemPrice {
                        $record->update($this->checked($data));

                        return $record;
                    }),
                DeleteAction::make()->label('Hapus')->visible(fn () => $this->canManage()),
            ])
            ->emptyStateHeading('Belum ada harga khusus')
            ->emptyStateDescription('Menu memakai harga dasar/varian di semua outlet dan channel.');
    }
}
