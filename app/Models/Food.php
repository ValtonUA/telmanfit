<?php

namespace App\Models;

use App\Models\Catalog\Ingredient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Food extends Model
{
    public $timestamps = false;

    protected $table = 'foods';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    public function getLabelAttribute(): string
    {
        return ($this->food_type === 'ingredient'
            ? $this->ingredient?->name
            : DB::table('recipe_versions')->where('food_id', $this->getKey())->value('title')) ?? '#'.$this->getKey();
    }

    public function ingredient(): HasOne
    {
        return $this->hasOne(Ingredient::class, 'food_id');
    }

    public function mealItems(): HasMany
    {
        return $this->hasMany(MealItem::class, 'food_id');
    }
}
