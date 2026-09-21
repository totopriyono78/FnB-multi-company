<?php

namespace App\Filament\Pages;

use App\Filament\Support\InventoryFields;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Inventory\Application\RecipeService;
use App\Modules\Inventory\Application\StockLocations;
use App\Modules\Inventory\Domain\Models\Ingredient;
use App\Modules\Inventory\Domain\Models\Recipe;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Penyusun resep (BOM) menu, varian, modifier, dan sub-resep bahan setengah jadi (FR-INV-03)
 * beserta HPP teoritis per porsi (FR-INV-10).
 *
 * @property-read Form $form
 */
class RecipeEditor extends Page implements HasForms
{
    use InteractsWithForms;

    public const TYPES = [
        Recipe::ITEM => 'Menu',
        Recipe::VARIANT => 'Varian menu',
        Recipe::MODIFIER => 'Modifier',
        Recipe::INGREDIENT => 'Bahan setengah jadi',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Resep';

    protected static ?string $title = 'Resep';

    protected static ?string $slug = 'resep';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.recipe-editor';

    #[Url(as: 'jenis')]
    public ?string $type = Recipe::ITEM;

    #[Url(as: 'target')]
    public ?string $target = null;

    #[Url(as: 'outlet')]
    public ?string $outletId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && (InventoryFields::canView() || $user->can('menu.view') || $user->can('menu.manage'));
    }

    public function mount(): void
    {
        if (! array_key_exists((string) $this->type, self::TYPES)) {
            $this->type = Recipe::ITEM;
        }
        if ($this->target !== null && ! array_key_exists($this->target, $this->targets())) {
            $this->target = null;
        }
        $outlets = $this->outlets();
        if ($this->outletId === null || ! array_key_exists($this->outletId, $outlets)) {
            $this->outletId = array_key_first($outlets);
        }
        $this->loadRecipe();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make()->columns(3)->schema([
                Select::make('type')->label('Resep untuk')->options(self::TYPES)->required()->live()
                    ->afterStateUpdated(function (?string $state): void {
                        $this->type = $state;
                        $this->target = null;
                        $this->loadRecipe();
                    }),
                Select::make('target')->label('Pilih')->options(fn () => $this->targets())->searchable()->live()
                    ->placeholder('Pilih menu atau bahan')
                    ->afterStateUpdated(function (?string $state): void {
                        $this->target = $state;
                        $this->loadRecipe();
                    }),
                Select::make('outlet')->label('HPP berdasarkan stok outlet')->options(fn () => $this->outlets())
                    ->placeholder('Harga beli terakhir')->live()
                    ->afterStateUpdated(fn (?string $state) => $this->outletId = $state),
            ]),
            Section::make('Komposisi')
                ->description(fn () => $this->type === Recipe::MODIFIER
                    ? 'Jumlah bahan per 1 modifier. Isi minus untuk mengurangi bahan menu, mis. "Tanpa Gula" = -15 ml gula cair.'
                    : ($this->type === Recipe::INGREDIENT ? 'Bahan untuk satu kali produksi; isi hasil produksinya di bawah.' : 'Jumlah bahan untuk 1 porsi, dalam satuan dasar bahan.'))
                ->visible(fn () => $this->target !== null)
                ->schema([
                    Grid::make(3)->schema([
                        InventoryFields::qty('yield_qty', 'Hasil produksi')->default('1')->rules(['gt:0'])
                            ->suffix(fn () => InventoryFields::baseUnit($this->target))
                            ->visible(fn () => $this->type === Recipe::INGREDIENT),
                        Textarea::make('notes')->label('Catatan penyajian')->rows(1)->maxLength(300)->columnSpan(2),
                    ]),
                    Repeater::make('lines')->label('Daftar bahan')->hiddenLabel()
                        ->schema([
                            Grid::make(3)->schema([
                                Select::make('ingredient_id')->label('Bahan')->options(fn () => InventoryFields::ingredientOptions())
                                    ->searchable()->required()->live()->columnSpan(2)
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                InventoryFields::qty('qty', 'Jumlah', $this->type === Recipe::MODIFIER)
                                    ->suffix(fn (Get $get) => InventoryFields::baseUnit($get('ingredient_id')))
                                    ->rules(['not_in:0']),
                            ]),
                        ])
                        ->addActionLabel('Tambah bahan')
                        ->defaultItems(0)
                        ->maxItems(50)
                        ->reorderableWithButtons(),
                    Placeholder::make('empty_hint')->hiddenLabel()
                        ->content('Kosongkan semua bahan lalu simpan untuk menghapus resep. Menu tanpa resep tidak memotong stok.'),
                ]),
        ]);
    }

    /** @return array<string, string> */
    public function targets(): array
    {
        $user = InventoryFields::user();
        if ($user === null) {
            return [];
        }
        $scope = app(MenuScope::class);

        return match ($this->type) {
            Recipe::ITEM => $scope->apply(Item::query(), $user)->where('type', Item::TYPE_SINGLE)->orderBy('name')
                ->get(['id', 'name', 'sku'])->mapWithKeys(fn (Item $i) => [$i->id => "{$i->name} ({$i->sku})"])->all(),
            Recipe::VARIANT => ItemVariant::query()->with('item:id,name,brand_id')
                ->whereHas('item', fn ($q) => $scope->apply($q, $user))
                ->get()->sortBy(fn (ItemVariant $v) => $v->item->name.$v->sort_order)
                ->mapWithKeys(fn (ItemVariant $v) => [$v->id => $v->item->name.' · '.$v->name])->all(),
            Recipe::MODIFIER => Modifier::query()->with('group:id,name,brand_id')
                ->whereHas('group', fn ($q) => $scope->apply($q, $user))
                ->get()->sortBy(fn (Modifier $m) => $m->group->name.$m->sort_order)
                ->mapWithKeys(fn (Modifier $m) => [$m->id => $m->group->name.' · '.$m->name])->all(),
            Recipe::INGREDIENT => Ingredient::query()->where('kind', Ingredient::SEMI)->orderBy('name')->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /** @return array<string, string> */
    public function outlets(): array
    {
        return InventoryFields::outletOptions();
    }

    public function canManage(): bool
    {
        $user = InventoryFields::user();
        if ($user === null || $this->target === null) {
            return false;
        }
        $service = app(RecipeService::class);

        return $service->canManage($user, $service->target((string) $this->type, $this->target)['brand_id']);
    }

    /** @return array<string, mixed>|null */
    public function cost(): ?array
    {
        if ($this->target === null || ! Recipe::query()->where('target_type', $this->type)->where('target_id', $this->target)->exists()) {
            return null;
        }
        $locationId = null;
        if ($this->outletId !== null && array_key_exists($this->outletId, $this->outlets())) {
            $outlet = Outlet::query()->find($this->outletId);
            $locationId = $outlet === null ? null : app(StockLocations::class)->forSale($outlet, null)->id;
        }

        return app(RecipeService::class)->cost((string) $this->type, $this->target, $locationId);
    }

    public function loadRecipe(): void
    {
        $recipe = $this->target === null ? null
            : Recipe::query()->with('lines')->where('target_type', $this->type)->where('target_id', $this->target)->first();
        $this->form->fill([
            'type' => $this->type,
            'target' => $this->target,
            'outlet' => $this->outletId,
            'yield_qty' => $recipe === null ? '1' : InventoryFields::plain((string) $recipe->yield_qty),
            'notes' => $recipe?->notes,
            'lines' => $recipe === null ? [] : $recipe->lines->mapWithKeys(fn ($l) => [(string) Str::uuid() => [
                'ingredient_id' => $l->ingredient_id,
                'qty' => InventoryFields::plain((string) $l->qty),
            ]])->all(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Simpan Resep')->icon('heroicon-m-check')
                ->visible(fn () => $this->canManage())
                ->action(function (): void {
                    $state = $this->form->getState();
                    $user = InventoryFields::user();
                    if ($user === null || $this->target === null) {
                        return;
                    }
                    try {
                        MenuFields::run(fn () => app(RecipeService::class)->save($user, (string) $this->type, (string) $this->target, [
                            'yield_qty' => $state['yield_qty'] ?? '1',
                            'notes' => $state['notes'] ?? null,
                            'lines' => array_values(array_map(fn (array $l) => ['ingredient_id' => $l['ingredient_id'], 'qty' => (string) $l['qty']], $state['lines'] ?? [])),
                        ]));
                    } catch (Halt) {
                        return;
                    }
                    Notification::make()->success()->title(($state['lines'] ?? []) === [] ? 'Resep dihapus.' : 'Resep disimpan.')->send();
                    $this->loadRecipe();
                }),
        ];
    }
}
