<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'meal_plan_versions';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'published_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(MealPlanDay::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ClientMealPlanAssignment::class);
    }
}
