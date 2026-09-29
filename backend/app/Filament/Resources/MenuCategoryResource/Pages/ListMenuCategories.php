<?php

namespace App\Filament\Resources\MenuCategoryResource\Pages;

use App\Filament\Resources\MenuCategoryResource;
use App\Filament\Support\MenuFields;
use App\Modules\Catalog\Application\MenuCategorySpreadsheet;
use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Tenancy\Application\WritableCompany;
use App\Modules\Tenancy\Domain\Models\Brand;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Daftar kategori menu + alat impor/ekspor (FR-MENU-09). */
class ListMenuCategories extends ListRecords
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->importAction(),
                $this->exportAction(),
            ])->label('Alat Kategori')->button()->color('gray')->icon('heroicon-m-wrench-screwdriver'),
            CreateAction::make()->label('Tambah Kategori'),
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
            ->modalHeading('Impor kategori menu')
            ->modalDescription('Kolom wajib: nama. Kolom lain: warna, ikon, urutan, aktif. '
                .'Kategori dicocokkan berdasarkan namanya — yang sudah ada diperbarui, yang belum dibuat baru. '
                .'Kategori yang tidak ada di file tidak diubah maupun dihapus. '
                .'Kolom yang dikosongkan mempertahankan nilai yang tersimpan.')
            ->modalSubmitActionLabel('Proses')
            ->form([
                Select::make('brand_id')->label('Brand')->options(fn () => MenuFields::manageableBrands())->required(),
                FileUpload::make('file')->label('File kategori')
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

                $report = MenuFields::run(
                    fn () => app(MenuCategorySpreadsheet::class)->import($brand, $file->getRealPath(), $extension, (bool) $data['dry_run']),
                    'mountedActionsData.0.'
                );

                if ($report['errors'] !== []) {
                    $lines = collect($report['errors'])->take(8)->map(fn ($e) => "Baris {$e['row']}: {$e['message']}");
                    $more = count($report['errors']) > 8 ? '<br>dan '.(count($report['errors']) - 8).' kesalahan lain.' : '';
                    Notification::make()->danger()->persistent()
                        ->title('Impor dibatalkan, perbaiki file terlebih dahulu')
                        ->body($lines->map(fn ($l) => e($l))->implode('<br>').$more)
                        ->send();
                    $action->halt();
                }

                Notification::make()->success()
                    ->title($report['dry_run'] ? 'File valid. Belum ada yang disimpan.' : 'Impor selesai')
                    ->body("{$report['created']} kategori baru, {$report['updated']} diperbarui.")
                    ->send();
            });
    }

    private function exportAction(): Action
    {
        return Action::make('export')->label('Ekspor ke Excel')->icon('heroicon-m-arrow-down-tray')
            ->modalHeading('Ekspor kategori menu')
            ->modalDescription('File hasil ekspor dapat diubah lalu diimpor kembali. '
                .'Brand yang belum punya kategori menghasilkan file berisi baris judul saja — pakai itu sebagai template.')
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
                $path = app(MenuCategorySpreadsheet::class)->export($brand);

                return response()->download($path, 'kategori-'.Str::slug($brand->name).'-'.now('Asia/Jakarta')->format('Ymd').'.xlsx')
                    ->deleteFileAfterSend();
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
}
