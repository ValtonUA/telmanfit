<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

class RecipeCategory extends Model
{
    public $timestamps = false;

    protected $table = 'recipe_categories';

    protected $fillable = ['name'];
}
