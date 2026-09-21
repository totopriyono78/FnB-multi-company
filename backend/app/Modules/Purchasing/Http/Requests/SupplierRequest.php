<?php

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Inventory\Http\Requests\InventoryRequest;
use App\Modules\Purchasing\Domain\Models\Supplier;
use Closure;
use Illuminate\Support\Facades\DB;

/** Data pemasok (FR-PUR) */
class SupplierRequest extends InventoryRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Supplier|null $supplier */
        $supplier = $this->route('supplier');
        $req = $supplier ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/', $this->uniqueCode($supplier?->id)],
            'name' => [$req, 'string', 'max:100'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-() ]{6,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'address' => ['nullable', 'string', 'max:300'],
            'payment_term_days' => ['sometimes', 'integer', 'between:0,365'],
            'notes' => ['nullable', 'string', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['code' => 'kode pemasok', 'name' => 'nama pemasok', 'phone' => 'nomor telepon', 'payment_term_days' => 'tempo pembayaran'];
    }

    /** @return Closure(string, mixed, Closure): void */
    private function uniqueCode(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (! is_string($value)) {
                return;
            }
            $exists = DB::table('suppliers')
                ->where('company_id', $this->companyId())
                ->whereNull('deleted_at')
                ->whereRaw('lower(code) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
            if ($exists) {
                $fail('Kode pemasok sudah dipakai.');
            }
        };
    }
}
