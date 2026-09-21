<?php

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\Models\Ingredient;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** FR-INV-01 */
class IngredientRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Ingredient|null $ingredient */
        $ingredient = $this->route('ingredient');
        $req = $ingredient ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueCode($ingredient?->id)],
            'name' => [$req, 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:40'],
            // Satuan dasar tidak boleh diubah setelah dibuat (resep & saldo memakai satuan ini).
            'base_unit' => [$ingredient ? 'prohibited' : 'required', Rule::in(array_keys(Ingredient::BASE_UNITS))],
            'kind' => [$ingredient ? 'prohibited' : 'sometimes', Rule::in(array_keys(Ingredient::KINDS))],
            'min_stock' => ['sometimes', ...self::qty(true, true)],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:300'],
            'units' => ['sometimes', 'array', 'max:10'],
            'units.*.name' => ['required', 'string', 'max:20', 'distinct:ignore_case', Rule::notIn(array_keys(Ingredient::BASE_UNITS))],
            'units.*.factor' => ['required', 'decimal:0,4', 'regex:'.self::PLAIN_DECIMAL, 'gt:0', 'max:99999999'],
            'units.*.is_purchase_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code' => 'kode bahan', 'name' => 'nama bahan', 'base_unit' => 'satuan dasar', 'min_stock' => 'stok minimum',
            'units.*.name' => 'nama satuan', 'units.*.factor' => 'isi per satuan',
        ];
    }

    /** @return Closure(string, mixed, Closure): void */
    private function uniqueCode(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (! is_string($value)) {
                return;
            }
            $exists = DB::table('ingredients')
                ->where('company_id', $this->companyId())
                ->whereNull('deleted_at')
                ->whereRaw('lower(code) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                $fail('Kode bahan sudah dipakai.');
            }
        };
    }
}
