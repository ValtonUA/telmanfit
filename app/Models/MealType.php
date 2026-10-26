<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealType extends Model
{
    public $timestamps = false;

    protected $table = 'meal_types';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }
}
