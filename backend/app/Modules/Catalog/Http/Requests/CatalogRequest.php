<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Tenancy\Application\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class CatalogRequest extends FormRequest
{
    /** Angka desimal biasa tanpa tanda/eksponen: 18000, 18000.5, 0.25 (bukan "+5", ".5", "5."). */
    public const PLAIN_DECIMAL = '/^\d+(\.\d+)?$/';

    public function authorize(): bool
    {
        return true; // Otorisasi di controller lewat policy.
    }

    protected function companyId(): ?string
    {
        return app(TenantContext::class)->companyId();
    }

    protected function existsInCompany(string $table, bool $withoutTrashed = false): Exists
    {
        $rule = Rule::exists($table, 'id')->where('company_id', $this->companyId());

        return $withoutTrashed ? $rule->whereNull('deleted_at') : $rule;
    }

    /**
     * Unik tanpa membedakan huruf besar/kecil dalam satu brand (selaras dengan indeks lower() di database).
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function uniqueInBrand(string $table, string $column, ?string $brandId, ?string $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $column, $brandId, $ignoreId, $message): void {
            if (! is_string($value) || $brandId === null) {
                return;
            }
            $exists = DB::table($table)
                ->where('company_id', $this->companyId())
                ->where('brand_id', $brandId)
                ->whereNull('deleted_at')
                ->whereRaw("lower({$column}) = lower(?)", [$value])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                $fail($message);
            }
        };
    }

    /**
     * Aturan harga: desimal 2 angka, tidak negatif.
     *
     * @return list<string>
     */
    protected static function money(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'decimal:0,2', 'regex:'.self::PLAIN_DECIMAL, 'min:0', 'max:9999999999'];
    }

    /**
     * Nilai input yang diharapkan berupa daftar; selain array dianggap kosong (aturan 'array' yang melaporkan galatnya).
     *
     * @return array<array-key, mixed>
     */
    protected function arrayInput(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    protected static function scheduleRules(string $key): array
    {
        return [
            $key => ['nullable', 'array', 'max:14'],
            "{$key}.*.days" => ['nullable', 'array'],
            "{$key}.*.days.*" => ['integer', 'between:1,7'],
            "{$key}.*.start" => ['required', 'date_format:H:i'],
            "{$key}.*.end" => ['required', 'date_format:H:i', 'different:'."{$key}.*.start"],
        ];
    }
}
