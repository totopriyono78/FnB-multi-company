<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Filament\Support\InventoryFields;
use App\Modules\Purchasing\Domain\Models\PurchaseOrder;
use App\Modules\Purchasing\Domain\Models\Supplier;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Data pemasok (FR-PUR). */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Pembelian';

    protected static ?string $modelLabel = 'pemasok';

    protected static ?string $pluralModelLabel = 'Pemasok';

    protected static ?string $slug = 'pemasok';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(3)->schema([
                TextInput::make('code')->label('Kode')->required()->maxLength(20)->regex('/^[A-Za-z0-9._-]+$/')->placeholder('SUP-001')
                    ->rule(fn (?Supplier $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $taken = Supplier::query()->whereRaw('lower(code) = lower(?)', [(string) $value])
                            ->when($record !== null, fn ($q) => $q->whereKeyNot($record->id))->exists();
                        if ($taken) {
                            $fail('Kode pemasok sudah dipakai.');
                        }
                    }),
                TextInput::make('name')->label('Nama pemasok')->required()->maxLength(100)->columnSpan(2)->placeholder('CV Sumber Susu Lembang'),
                TextInput::make('contact_name')->label('Nama kontak')->maxLength(80),
                TextInput::make('phone')->label('Telepon / WhatsApp')->tel()->maxLength(20)->regex('/^[0-9+\-() ]{6,20}$/'),
                TextInput::make('email')->label('Email')->email()->maxLength(120),
                Textarea::make('address')->label('Alamat')->rows(2)->maxLength(300)->columnSpan(2),
                TextInput::make('payment_term_days')->label('Tempo pembayaran')->integer()->minValue(0)->maxValue(365)->default(0)->suffix('hari'),
                Textarea::make('notes')->label('Catatan')->rows(2)->maxLength(300)->columnSpan(2),
                Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Kontak')->placeholder('-')->description(fn (Supplier $r) => $r->phone),
                TextColumn::make('payment_term_days')->label('Tempo')->formatStateUsing(fn (int $state) => $state === 0 ? 'Tunai' : "{$state} hari"),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->defaultSort('name')
            ->filters([TernaryFilter::make('is_active')->label('Status')->trueLabel('Aktif')->falseLabel('Nonaktif')])
            ->actions([
                EditAction::make()->label('Ubah'),
                DeleteAction::make()->label('Hapus')->modalHeading('Hapus pemasok?')
                    ->before(function (Supplier $record, DeleteAction $action): void {
                        $open = PurchaseOrder::query()->where('supplier_id', $record->id)
                            ->whereIn('status', [PurchaseOrder::DRAFT, PurchaseOrder::SUBMITTED, PurchaseOrder::APPROVED, PurchaseOrder::PARTIALLY_RECEIVED])->exists();
                        if ($open) {
                            Notification::make()->danger()->title('Pemasok masih memiliki purchase order yang berjalan.')->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada pemasok')
            ->emptyStateDescription('Tambahkan pemasok bahan seperti distributor susu, roastery, atau toko bahan kue.');
    }

    public static function canViewAny(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && $user->can('viewAny', Supplier::class);
    }

    public static function canCreate(): bool
    {
        $user = InventoryFields::user();

        return $user !== null && $user->can('create', Supplier::class);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
