<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Application\MediaStore;
use App\Modules\Tenancy\Domain\Models\Brand;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** FR-TEN-04 */
class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Organisasi';

    protected static ?string $modelLabel = 'brand';

    protected static ?string $pluralModelLabel = 'Brand';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->label('Kode')
                ->required()->maxLength(20)
                ->regex('/^[A-Z0-9\-]+$/')
                ->mutateStateForValidationUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                ->extraInputAttributes(['class' => 'uppercase'])
                ->helperText('Huruf besar, angka, atau tanda hubung. Contoh: KTJ')
                ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', filament()->getTenant()?->getKey())),
            TextInput::make('name')->label('Nama brand')->required()->maxLength(100),
            Toggle::make('is_active')->label('Aktif')->default(true),
            /*
             * Logo dicetak di kepala struk, tetapi hanya pada outlet yang menyalakan
             * "Cetak logo" (Outlet -> Struk). Printer struk mencetak hitam-putih, jadi logo
             * berwarna gelap-terang tegas terbaca jauh lebih baik daripada gradasi halus.
             */
            FileUpload::make('logo_path')
                ->label('Logo')
                ->helperText('Dicetak di struk bila outlet menyalakan "Cetak logo". PNG berlatar transparan paling rapi; diperkecil otomatis ke 512 px.')
                ->image()
                ->maxSize((int) config('fnb.media.max_upload_kb'))
                ->disk(fn () => app(MediaStore::class)->diskName())
                ->directory('logo')
                ->visibility('public')
                ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => app(MediaStore::class)->put($file, 'logo'))
                ->deleteUploadedFileUsing(fn (?string $file) => app(MediaStore::class)->forget($file))
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->sortable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('outlets_count')->label('Outlet')->counts('outlets')->numeric()->alignEnd(),
                TextColumn::make('is_active')->label('Status')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('updated_at')->label('Diubah')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->actions([EditAction::make()->label('Ubah')])
            ->emptyStateHeading('Belum ada brand')
            ->emptyStateDescription('Buat brand pertama, lalu tambahkan outlet di bawahnya.');
    }

    /**
     * Batasi daftar sesuai cakupan brand/outlet user (FR-AUTH-06).
     *
     * @return Builder<Brand>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $scope = app(AccessScope::class)->for($user);
        if ($scope === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('id', $scope['brands'])
            ->orWhereHas('outlets', fn (Builder $o) => $o->whereIn('id', $scope['outlets'])));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}
