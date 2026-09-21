<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesChannelResource\Pages;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** Channel penjualan: dine-in, take away, ojek online, dsb. (FR-MENU-06) */
class SalesChannelResource extends Resource
{
    protected static ?string $model = SalesChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Menu & Harga';

    protected static ?string $modelLabel = 'channel penjualan';

    protected static ?string $pluralModelLabel = 'Channel Penjualan';

    protected static ?string $slug = 'channel-penjualan';

    protected static ?int $navigationSort = 9;

    public const TYPES = ['in_store' => 'Di toko', 'aggregator' => 'Ojek online', 'online' => 'Pesan online'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Kode')->required()->maxLength(30)
                ->regex('/^[a-z0-9_]+$/')
                ->helperText('Huruf kecil, angka, atau garis bawah. Tidak dapat diubah setelah disimpan.')
                ->disabledOn('edit')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', filament()->getTenant()?->getKey())),
            TextInput::make('name')->label('Nama channel')->required()->maxLength(60),
            Select::make('type')->label('Jenis')->options(self::TYPES)->in(array_keys(self::TYPES))->required()->disabledOn('edit'),
            TextInput::make('sort_order')->label('Urutan')->integer()->minValue(0)->maxValue(32767)->default(0),
            Toggle::make('service_charge_applies')->label('Kenakan service charge')
                ->helperText('Biasanya hanya untuk makan di tempat.')->inline(false),
            Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('code')->label('Kode')->fontFamily('mono'),
                TextColumn::make('type')->label('Jenis')->formatStateUsing(fn (string $state) => self::TYPES[$state] ?? $state),
                TextColumn::make('service_charge_applies')->label('Service charge')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Ya' : 'Tidak')
                    ->color(fn (bool $state) => $state ? 'info' : 'gray'),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('sort_order')
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada channel penjualan');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesChannels::route('/'),
            'create' => Pages\CreateSalesChannel::route('/create'),
            'edit' => Pages\EditSalesChannel::route('/{record}/edit'),
        ];
    }
}
