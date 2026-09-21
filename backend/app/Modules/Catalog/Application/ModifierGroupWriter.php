<?php

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Events\MenuChanged;
use App\Modules\Catalog\Domain\Models\Modifier;
use App\Modules\Catalog\Domain\Models\ModifierGroup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Menyimpan grup modifier beserta pilihannya (FR-MENU-04). */
class ModifierGroupWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(ModifierGroup $group, array $data): ModifierGroup
    {
        return DB::transaction(function () use ($group, $data): ModifierGroup {
            $group->fill(Arr::only($data, ['brand_id', 'name', 'min_select', 'max_select', 'sort_order', 'is_active']))->save();

            if (array_key_exists('modifiers', $data)) {
                $keep = [];
                /** @var list<array<string, mixed>> $rows */
                $rows = array_values($data['modifiers'] ?? []);
                foreach ($rows as $i => $row) {
                    $modifier = isset($row['id'])
                        ? Modifier::query()->where('modifier_group_id', $group->id)->findOrFail($row['id'])
                        : new Modifier;
                    $modifier->modifier_group_id = $group->id;
                    $modifier->fill([
                        'name' => $row['name'],
                        'price' => $row['price'],
                        'is_default' => (bool) ($row['is_default'] ?? false),
                        'sort_order' => $i,
                        'is_active' => $row['is_active'] ?? true,
                    ])->save();
                    $keep[] = $modifier->id;
                }
                Modifier::query()->where('modifier_group_id', $group->id)->whereNotIn('id', $keep)->delete();
            }

            MenuChanged::dispatch($group->company_id, 'modifier_group', $group->id);

            return $group->load('modifiers');
        });
    }
}
