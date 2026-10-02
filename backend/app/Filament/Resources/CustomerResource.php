<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Filament\Support\TreasuryAccess;
use App\Modules\Treasury\Domain\Models\Customer;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Pelanggan yang ditagih (AR-01).
 *
 * Bukan tamu kasir. Yang perlu punya nama di sini hanyalah pihak yang tagihannya dibayar belakangan —
 * karena hanya pihak itu yang bisa menunggak, dan hanya tunggakan yang perlu ditagih.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Kas & Hutang';

    protected static ?string $modelLabel = 'pelanggan';

    protected static ?string $pluralModelLabel = 'Pelanggan Tagihan';

    protected static ?string $slug = 'piutang/pelanggan';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return TreasuryAccess::canView();
    }

    public static function canCreate(): bool
    {
        return TreasuryAccess::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return TreasuryAccess::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        // Dinonaktifkan, bukan dihapus: tagihan lama tetap harus punya nama pelanggannya.
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(3)->schema([
                TextInput::make('code')->label('Kode')->required()->maxLength(20),
                TextInput::make('name')->label('Nama pelanggan')->required()->maxLength(150)->columnSpan(2),
                TextInput::make('contact_name')->label('Nama kontak')->maxLength(100),
                TextInput::make('phone')->label('Telepon / WhatsApp')->tel()->maxLength(30),
                TextInput::make('email')->label('Email')->email()->maxLength(120),
                Textarea::make('address')->label('Alamat')->rows(2)->maxLength(300)->columnSpan(2),
                TextInput::make('npwp')->label('NPWP')->maxLength(25),
                TextInput::make('payment_term_days')->label('Tempo pembayaran')->integer()
                    ->minValue(0)->maxValue(365)->default(0)->suffix('hari')
                    ->helperText('Dipakai menghitung jatuh tempo tagihan.'),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300)->columnSpan(2),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable()
                    ->description(fn (Customer $record) => $record->contact_name),
                TextColumn::make('phone')->label('Telepon')->placeholder('-'),
                TextColumn::make('payment_term_days')->label('Tempo')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Tunai' : "{$state} hari"),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif')])
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada pelanggan tagihan')
            ->emptyStateDescription('Daftarkan pihak yang membayar belakangan: katering korporat, penyewa tempat, mitra acara.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/baru'),
            'edit' => Pages\EditCustomer::route('/{record}/ubah'),
        ];
    }
}
