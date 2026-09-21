<?php

namespace App\Filament\Support;

use App\Modules\Catalog\Application\MenuScope;
use App\Modules\Catalog\Domain\Models\SalesChannel;
use App\Modules\Identity\Application\AccessScope;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Shared\Domain\Exceptions\ConflictException;
use App\Modules\Tenancy\Application\TenantContext;
use App\Modules\Tenancy\Domain\Models\Brand;
use App\Modules\Tenancy\Domain\Models\Outlet;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/** Komponen & helper bersama untuk halaman menu di back-office. */
final class MenuFields
{
    public const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** Input nominal rupiah: angka, maks. 2 desimal, tidak negatif. */
    public static function money(string $name, string $label, bool $required = true): TextInput
    {
        return TextInput::make($name)->label($label)
            ->prefix('Rp')
            ->inputMode('decimal')
            ->rules(['decimal:0,2', 'min:0', 'max:9999999999'])
            ->required($required)
            ->formatStateUsing(fn ($state) => $state === null ? null : self::plain((string) $state))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (string) $state);
    }

    /** "18000.00" → "18000", "18000.50" tetap. */
    public static function plain(string $value): string
    {
        return str_ends_with($value, '.00') ? substr($value, 0, -3) : $value;
    }

    public static function rupiah(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        [$int, $dec] = array_pad(explode('.', (string) $value), 2, '00');
        $negative = str_starts_with($int, '-');
        $int = ltrim($int, '-');
        $formatted = 'Rp'.number_format((float) $int, 0, ',', '.');
        if ($dec !== '' && (int) $dec !== 0) {
            $formatted .= ','.str_pad($dec, 2, '0');
        }

        return ($negative ? '-' : '').$formatted;
    }

    /**
     * Brand yang boleh DIKELOLA user (untuk form buat/ubah).
     *
     * @return array<string, string>
     */
    public static function manageableBrands(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }
        $scope = app(MenuScope::class);

        return Brand::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (Brand $b) => $scope->canManageBrand($user, $b->id))
            ->pluck('name', 'id')->all();
    }

    /**
     * Brand yang boleh DILIHAT user (untuk filter).
     *
     * @return array<string, string>
     */
    public static function visibleBrands(): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }

        return app(MenuScope::class)->apply(Brand::query(), $user, 'id')->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    public static function outlets(?string $brandId = null): array
    {
        $user = self::user();
        if ($user === null) {
            return [];
        }

        return app(AccessScope::class)
            ->applyToOutletQuery(Outlet::query()->where('is_active', true), $user)
            ->when($brandId !== null, fn (Builder $q) => $q->where('brand_id', $brandId))
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Outlet $o) => [$o->id => "{$o->name} ({$o->code})"])
            ->all();
    }

    /** @return array<string, string> kode => nama */
    public static function channels(): array
    {
        return SalesChannel::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    /** Aturan validasi per nilai: kode channel harus ada di company aktif. */
    public static function knownChannel(): Exists
    {
        return Rule::exists('sales_channels', 'code')->where('company_id', app(TenantContext::class)->companyId());
    }

    /**
     * Terapkan cakupan brand user pada query daftar.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scopeQuery(Builder $query, string $column = 'brand_id'): Builder
    {
        $user = self::user();

        return $user === null ? $query->whereRaw('1 = 0') : app(MenuScope::class)->apply($query, $user, $column);
    }

    /**
     * Jalankan aksi layanan dan ubah galat domain menjadi notifikasi/galat form Filament.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    public static function run(Closure $action, string $formPrefix = 'data.'): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($m, $k) => [$formPrefix.$k => $m])->all()
            );
        } catch (AuthorizationException|ConflictException $e) {
            Notification::make()->danger()->title($e->getMessage() ?: 'Anda tidak memiliki akses untuk aksi ini.')->send();
            throw new Halt;
        }
    }
}
