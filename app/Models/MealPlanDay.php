<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanDay extends Model
{
    public $timestamps = false;

    protected $table = 'meal_plan_days';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['day_number' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MealPlanVersion::class, 'meal_plan_version_id');
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }
}
