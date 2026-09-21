<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KitchenStationResource\Pages;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\CatalogRemover;
use App\Modules\Catalog\Domain\Models\KitchenStation;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** Stasiun dapur tujuan tiket KDS/printer (FR-MENU-14, FR-KDS). */
class KitchenStationResource extends Resource
{
    protected static ?string $model = KitchenStation::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'stasiun dapur';

    protected static ?string $pluralModelLabel = 'Stasiun Dapur';

    protected static ?string $slug = 'stasiun-dapur';

    protected static ?int $navigationSort = 8;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Kode')->required()->maxLength(20)
                ->regex('/^[A-Z0-9_]+$/')
                ->mutateStateForValidationUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                ->extraInputAttributes(['class' => 'uppercase'])
                ->helperText('Huruf besar, angka, atau garis bawah. Contoh: BAR')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', filament()->getTenant()?->getKey())),
            TextInput::make('name')->label('Nama stasiun')->required()->maxLength(60),
            TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(32767)->default(0),
            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('items'))
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('items_count')->label('Menu')->numeric()->alignEnd(),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')
                    ->modalHeading('Hapus stasiun?')
                    ->using(function (KitchenStation $record): bool {
                        MenuFields::run(fn () => app(CatalogRemover::class)->station($record));

                        return true;
                    }),
            ])
            ->emptyStateHeading('Belum ada stasiun dapur');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKitchenStations::route('/'),
            'create' => Pages\CreateKitchenStation::route('/create'),
            'edit' => Pages\EditKitchenStation::route('/{record}/edit'),
        ];
    }
}
