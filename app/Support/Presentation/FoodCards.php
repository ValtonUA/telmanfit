<?php

namespace App\Support\Presentation;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Один batched lookup: exact food → логічний recipe cover, без step images/latest. */
final class FoodCards
{
    public function lookup(array $foodIds): Collection
    {
        if ($foodIds === []) {
            return collect();
        }

        return DB::table('foods as f')->leftJoin('ingredients as i', 'i.food_id', '=', 'f.id')
            ->leftJoin('recipe_versions as v', 'v.food_id', '=', 'f.id')->leftJoin('recipes as r', 'r.id', '=', 'v.recipe_id')
            ->whereIn('f.id', array_unique($foodIds))->get(['f.id', 'f.food_type', 'i.name', 'v.title', 'i.image_url as ingredient_image', 'r.image_url as recipe_image'])
            ->mapWithKeys(fn ($row) => [$row->id => ['label' => ($row->food_type === 'ingredient' ? $row->name : $row->title) ?? '#'.$row->id,
                'image' => PublicImageUrl::safe($row->food_type === 'ingredient' ? $row->ingredient_image : $row->recipe_image)]]);
    }
}
