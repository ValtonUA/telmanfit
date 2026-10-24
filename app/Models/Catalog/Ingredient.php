<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    public $timestamps = false;

    protected $table = 'ingredients';

    protected $fillable = ['name', 'ingredient_category_id', 'kcal_per_100g', 'protein_per_100g', 'fat_per_100g', 'carbs_per_100g'];
}
