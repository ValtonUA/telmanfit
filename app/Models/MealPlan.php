<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'meal_plans';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = ['title', 'week_start_weekday'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'week_start_weekday' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MealPlanVersion::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'owner_client_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'parent_meal_plan_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(MealPlan::class, 'parent_meal_plan_id');
    }
}
