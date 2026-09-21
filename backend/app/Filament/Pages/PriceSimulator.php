<?php

namespace App\Filament\Pages;

use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\QuoteService;
use App\Modules\Catalog\Domain\Models\Item;
use App\Modules\Catalog\Domain\Models\ItemVariant;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Brick\Math\BigDecimal;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

/**
 * Simulasi harga dengan konfigurasi outlet sebenarnya (pajak, service charge, pembulatan, promo).
 * Membantu pemilik memeriksa pengaturan sebelum dipakai kasir. Tidak menyimpan transaksi.
 *
 * @property-read Form $form
 */
class PriceSimulator extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $navigationLabel = 'Simulasi Harga';

    protected static ?string $title = 'Simulasi Harga';

    protected static ?string $slug = 'simulasi-harga';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.price-simulator';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /** @var list<string> */
    public array $problems = [];

    public static function canAccess(): bool
    {
        $user = MenuFields::user();

        return $user !== null && ($user->can('menu.view') || $user->can('menu.manage')) && MenuFields::outlets() !== [];
    }

    public function mount(): void
    {
        $this->form->fill([
            'outlet_id' => array_key_first(MenuFields::outlets()),
            'channel_code' => 'dine_in',
            'lines' => [['qty' => 1]],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Grid::make(4)->schema([
                Select::make('outlet_id')->label('Outlet')->options(fn () => MenuFields::outlets())->required()->live()
                    ->afterStateUpdated(fn () => $this->result = null),
                Select::make('channel_code')->label('Channel')->options(fn () => MenuFields::channels())->required(),
                DateTimePicker::make('at')->label('Waktu pesanan')->seconds(false)->placeholder('Sekarang')
                    ->helperText('Untuk menguji promo jam tertentu.'),
                TagsInput::make('promo_codes')->label('Kode promo')->placeholder('Ketik lalu Enter'),
            ]),
            Repeater::make('lines')->label('Pesanan')
                ->schema([
                    Grid::make(6)->schema([
                        Select::make('item_id')->label('Menu')->required()->live()->columnSpan(2)
                            ->options(function (Get $get): array {
                                $outlet = $get('../../outlet_id') ? Outlet::query()->find($get('../../outlet_id')) : null;

                                return $outlet ? Item::query()->where('brand_id', $outlet->brand_id)->where('is_active', true)
                                    ->orderBy('name')->pluck('name', 'id')->all() : [];
                            }),
                        Select::make('variant_id')->label('Varian')
                            ->options(fn (Get $get) => $get('item_id') ? ItemVariant::query()->where('item_id', $get('item_id'))->pluck('name', 'id')->all() : [])
                            ->placeholder('-'),
                        CheckboxList::make('modifier_ids')->label('Modifier')->columnSpan(2)->columns(1)
                            ->options(function (Get $get): array {
                                $item = $get('item_id') ? Item::query()->with('modifierGroups.modifiers')->find($get('item_id')) : null;
                                if ($item === null) {
                                    return [];
                                }

                                return $item->modifierGroups->flatMap(fn ($g) => $g->modifiers->mapWithKeys(
                                    fn (Modifier $m) => [$m->id => "{$g->name}: {$m->name}".((float) $m->price > 0 ? ' (+'.MenuFields::rupiah((string) $m->price).')' : '')]
                                ))->all();
                            }),
                        TextInput::make('qty')->label('Jumlah')->numeric()->minValue(0.001)->maxValue(9999)->default(1)->required(),
                    ]),
                ])
                ->minItems(1)->maxItems(30)
                ->addActionLabel('Tambah menu'),
            Grid::make(4)->schema([
                Select::make('order_discount_type')->label('Diskon manual')
                    ->options(['percent' => 'Persen', 'amount' => 'Nominal'])->placeholder('Tanpa diskon')->live(),
                TextInput::make('order_discount_value')->label('Nilai diskon')->inputMode('decimal')
                    ->rules(['decimal:0,2', 'min:0'])
                    ->visible(fn (Get $get) => filled($get('order_discount_type')))
                    ->required(fn (Get $get) => filled($get('order_discount_type'))),
            ]),
        ]);
    }

    public function calculate(): void
    {
        $this->result = null;
        $this->problems = [];
        $data = $this->form->getState();

        $user = MenuFields::user();
        $outlet = Outlet::query()->find($data['outlet_id']);
        if ($user === null || $outlet === null || ! app(AccessScope::class)->allowsOutlet($user, $outlet)) {
            $this->problems = ['Outlet tidak ditemukan atau di luar akses Anda.'];

            return;
        }

        $request = [
            'channel_code' => $data['channel_code'],
            'at' => $data['at'] ?? null,
            'promo_codes' => $data['promo_codes'] ?? [],
            'lines' => array_values(array_map(fn (array $line) => [
                'item_id' => $line['item_id'],
                'variant_id' => $line['variant_id'] ?? null,
                'qty' => (string) $line['qty'],
                'modifiers' => array_map(fn ($id) => ['id' => $id, 'qty' => 1], array_values($line['modifier_ids'] ?? [])),
            ], $data['lines'])),
            'order_discounts' => filled($data['order_discount_type'] ?? null)
                ? [['type' => $data['order_discount_type'], 'value' => (string) $data['order_discount_value']]]
                : [],
        ];

        try {
            $this->result = app(QuoteService::class)->quote($outlet, $request);
        } catch (ValidationException $e) {
            $this->problems = array_values(array_unique(array_merge(...array_values($e->errors()))));
        } catch (\InvalidArgumentException $e) {
            $this->problems = [$e->getMessage()];
        }
    }

    /** @param  array<string, mixed>  $line */
    public function lineDiscount(array $line): string
    {
        $total = BigDecimal::of((string) $line['item_discount'])->plus((string) $line['order_discount']);

        return $total->isZero() ? '-' : '-'.MenuFields::rupiah((string) $total->toScale(2));
    }

    public function rupiah(string|int|float|null $value): string
    {
        return MenuFields::rupiah($value === null ? null : (string) $value);
    }
}
