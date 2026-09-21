<?php

namespace App\Filament\Resources\ItemResource\Pages;

use App\Filament\Resources\ItemResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\MenuCopier;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Application\MenuSpreadsheet;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Daftar menu + alat impor/ekspor dan salin menu (FR-MENU-09, FR-MENU-10). */
class ListItems extends ListRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->importAction(),
                $this->exportAction(),
                $this->copyBrandAction(),
                $this->copyOutletAction(),
            ])->label('Alat Menu')->button()->color('gray')->icon('heroicon-m-wrench-screwdriver'),
            CreateAction::make()->label('Tambah Menu'),
        ];
    }

    private function canManageAny(): bool
    {
        return WritableCompany::allows() && MenuFields::manageableBrands() !== [];
    }

    private function importAction(): Action
    {
        return Action::make('import')->label('Impor dari Excel/CSV')->icon('heroicon-m-arrow-up-tray')
            ->visible(fn () => $this->canManageAny())
            ->modalHeading('Impor menu')
            ->modalDescription('Kolom wajib: kategori, sku, nama, harga. Kolom lain: nama_singkat, varian (Regular=18000; Large=22000), stasiun, barcode, deskripsi, aktif. SKU yang sudah ada akan diperbarui.')
            ->modalSubmitActionLabel('Proses')
            ->form([
                Select::make('brand_id')->label('Brand')->options(fn () => MenuFields::manageableBrands())->required(),
                FileUpload::make('file')->label('File menu')
                    ->storeFiles(false)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])
                    ->maxSize(5120)->required(),
                Toggle::make('dry_run')->label('Periksa saja, jangan simpan')->default(true)
                    ->helperText('Matikan setelah hasil pemeriksaan sesuai.'),
            ])
            ->action(function (array $data, Action $action): void {
                $brand = $this->brandForManage((string) $data['brand_id']);
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];
                $extension = strtolower($file->getClientOriginalExtension());
                if (! in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
                    Notification::make()->danger()->title('Format file harus .xlsx atau .csv.')->send();
                    $action->halt();
                }

                $report = MenuFields::run(fn () => app(MenuSpreadsheet::class)->import($brand, $file->getRealPath(), $extension, (bool) $data['dry_run']), 'mountedActionsData.0.');

                if ($report['errors'] !== []) {
                    $lines = collect($report['errors'])->take(8)->map(fn ($e) => "Baris {$e['row']}: {$e['message']}");
                    $more = count($report['errors']) > 8 ? '<br>dan '.(count($report['errors']) - 8).' kesalahan lain.' : '';
                    Notification::make()->danger()->persistent()
                        ->title('Impor dibatalkan, perbaiki file terlebih dahulu')
                        ->body($lines->map(fn ($l) => e($l))->implode('<br>').$more)
                        ->send();
                    $action->halt();
                }

                $summary = "{$report['created']} menu baru, {$report['updated']} diperbarui, {$report['categories_created']} kategori baru.";
                Notification::make()->success()
                    ->title($report['dry_run'] ? 'File valid. Belum ada yang disimpan.' : 'Impor selesai')
                    ->body($summary)->send();
            });
    }

    private function exportAction(): Action
    {
        return Action::make('export')->label('Ekspor ke Excel')->icon('heroicon-m-arrow-down-tray')
            ->modalHeading('Ekspor menu')
            ->modalDescription('File hasil ekspor dapat diubah lalu diimpor kembali.')
            ->modalSubmitActionLabel('Unduh')
            ->form([
                Select::make('brand_id')->label('Brand')->options(fn () => MenuFields::visibleBrands())->required(),
            ])
            ->action(function (array $data): BinaryFileResponse {
                $user = MenuFields::user();
                $brand = Brand::query()->findOrFail($data['brand_id']);
                if ($user === null || ! app(MenuScope::class)->allowsBrand($user, $brand->id)) {
                    throw new AuthorizationException;
                }
                $path = app(MenuSpreadsheet::class)->export($brand);

                return response()->download($path, 'menu-'.Str::slug($brand->name).'-'.now('Asia/Jakarta')->format('Ymd').'.xlsx')
                    ->deleteFileAfterSend();
            });
    }

    private function copyBrandAction(): Action
    {
        return Action::make('copyBrand')->label('Salin menu ke brand lain')->icon('heroicon-m-document-duplicate')
            ->visible(fn () => $this->canManageAny())
            ->modalHeading('Salin menu antar brand')
            ->modalDescription('Kategori, grup modifier, dan menu disalin. Menu dengan SKU yang sudah ada di brand tujuan dilewati.')
            ->modalSubmitActionLabel('Salin')
            ->form([
                Select::make('from_brand_id')->label('Dari brand')->options(fn () => MenuFields::visibleBrands())->required()->live(),
                Select::make('to_brand_id')->label('Ke brand')->options(fn () => MenuFields::manageableBrands())->required()
                    ->different('from_brand_id'),
            ])
            ->action(function (array $data): void {
                $user = MenuFields::user();
                $from = Brand::query()->findOrFail($data['from_brand_id']);
                if ($user === null || ! app(MenuScope::class)->allowsBrand($user, $from->id)) {
                    throw new AuthorizationException;
                }
                $to = $this->brandForManage((string) $data['to_brand_id']);
                $report = MenuFields::run(fn () => app(MenuCopier::class)->copyBrand($from, $to), 'mountedActionsData.0.');

                $skipped = $report['items_skipped'] === [] ? '' : ' Dilewati (SKU sudah ada): '.implode(', ', array_slice($report['items_skipped'], 0, 10)).'.';
                Notification::make()->success()->title('Menu disalin')
                    ->body("{$report['items_copied']} menu, {$report['categories_created']} kategori, {$report['modifier_groups_created']} grup modifier.".$skipped)
                    ->send();
            });
    }

    private function copyOutletAction(): Action
    {
        return Action::make('copyOutlet')->label('Salin harga outlet')->icon('heroicon-m-building-storefront')
            ->visible(fn () => $this->canManageAny())
            ->modalHeading('Salin harga & ketersediaan antar outlet')
            ->modalDescription('Harga khusus dan status tampil menu disalin ke outlet tujuan di brand yang sama. Status habis tidak ikut disalin.')
            ->modalSubmitActionLabel('Salin')
            ->form([
                Select::make('from_outlet_id')->label('Dari outlet')->options(fn () => MenuFields::outlets())->required()->live(),
                Select::make('to_outlet_id')->label('Ke outlet')->required()
                    ->options(function (Get $get): array {
                        $from = $get('from_outlet_id') ? Outlet::query()->find($get('from_outlet_id')) : null;

                        return $from ? array_diff_key(MenuFields::outlets($from->brand_id), [$from->id => true]) : [];
                    }),
                Placeholder::make('note')->hiddenLabel()->content('Harga khusus yang sudah ada di outlet tujuan akan ditimpa.'),
            ])
            ->action(function (array $data): void {
                $user = MenuFields::user();
                $from = Outlet::query()->findOrFail($data['from_outlet_id']);
                $to = Outlet::query()->findOrFail($data['to_outlet_id']);
                $access = app(AccessScope::class);
                if ($user === null || ! $access->allowsOutlet($user, $from) || ! $access->allowsOutlet($user, $to)) {
                    throw new AuthorizationException;
                }
                $this->brandForManage($to->brand_id);
                $report = MenuFields::run(fn () => app(MenuCopier::class)->copyOutlet($from, $to), 'mountedActionsData.0.');

                Notification::make()->success()->title('Pengaturan outlet disalin')
                    ->body("{$report['prices_copied']} harga khusus dan {$report['availability_copied']} status tampil.")
                    ->send();
            });
    }

    private function brandForManage(string $brandId): Brand
    {
        $brand = Brand::query()->findOrFail($brandId);
        $user = MenuFields::user();
        if ($user === null || ! WritableCompany::allows() || ! app(MenuScope::class)->canManageBrand($user, $brand->id)) {
            Notification::make()->danger()->title('Anda tidak mengelola menu brand ini.')->send();
            throw new Halt;
        }

        return $brand;
    }

    public function getSubheading(): ?string
    {
        $count = ItemResource::getEloquentQuery()->where('is_active', true)->count();

        return $count > 0 ? "{$count} menu dijual" : null;
    }
}
