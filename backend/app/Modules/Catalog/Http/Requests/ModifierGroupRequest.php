<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Models\ModifierGroup;
use Closure;
use Illuminate\Validation\Validator;

/** FR-MENU-04 */
class ModifierGroupRequest extends CatalogRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ModifierGroup|null $group */
        $group = $this->route('modifierGroup');
        $req = $group ? 'sometimes' : 'required';

        return [
            'brand_id' => [$group ? 'prohibited' : 'required', 'uuid', $this->existsInCompany('brands', true)],
            'name' => [$req, 'string', 'max:60'],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'max_select' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
            'modifiers' => [$req, 'array', 'min:1', 'max:100'],
            'modifiers.*.id' => ['nullable', 'uuid'],
            'modifiers.*.name' => ['required', 'string', 'max:60'],
            'modifiers.*.price' => self::money(),
            'modifiers.*.is_default' => ['sometimes', 'boolean'],
            'modifiers.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            $group = $this->route('modifierGroup');
            $min = (int) $this->input('min_select', $group->min_select ?? 0);
            $max = (int) $this->input('max_select', $group->max_select ?? 1);
            if ($max < $min) {
                $v->errors()->add('max_select', 'Maksimal pilihan tidak boleh lebih kecil dari minimal pilihan.');
            }
            $defaults = collect($this->arrayInput('modifiers'))->where('is_default', true)->count();
            if ($defaults > $max) {
                $v->errors()->add('modifiers', "Pilihan default maksimal {$max}.");
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama grup', 'min_select' => 'minimal pilihan', 'max_select' => 'maksimal pilihan', 'modifiers' => 'pilihan', 'modifiers.*.name' => 'nama pilihan', 'modifiers.*.price' => 'harga pilihan'];
    }
}
