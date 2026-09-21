<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Domain\Models\MenuCategory;
use Illuminate\Validation\Rule;

/** FR-MENU-01 */
class MenuCategoryRequest extends CatalogRequest
{
    public const COLORS = ['gray', 'red', 'orange', 'amber', 'green', 'teal', 'sky', 'blue', 'violet', 'pink'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var MenuCategory|null $category */
        $category = $this->route('category');
        $req = $category ? 'sometimes' : 'required';

        return [
            'brand_id' => [$category ? 'prohibited' : 'required', 'uuid', $this->existsInCompany('brands', true)],
            'name' => [$req, 'string', 'max:60', $this->uniqueInBrand(
                'menu_categories', 'name', $category->brand_id ?? (is_string($this->input('brand_id')) ? $this->input('brand_id') : null),
                $category?->id, 'Nama kategori sudah dipakai di brand ini.',
            )],
            'color' => ['sometimes', Rule::in(self::COLORS)],
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^heroicon-[om]-[a-z0-9-]+$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['brand_id' => 'brand', 'name' => 'nama kategori', 'color' => 'warna', 'icon' => 'ikon'];
    }
}
