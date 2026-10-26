<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meal extends Model
{
    public $timestamps = false;

    protected $table = 'meals';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(MealPlanDay::class, 'meal_plan_day_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MealType::class, 'meal_type_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class);
    }
}
