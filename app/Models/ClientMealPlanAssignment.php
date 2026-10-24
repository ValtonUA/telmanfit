<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientMealPlanAssignment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'client_meal_plan_assignments';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MealPlanVersion::class, 'meal_plan_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(ClientMealPlanAssignment::class, 'replaced_by_assignment_id');
    }

    public function replaces(): HasMany
    {
        return $this->hasMany(ClientMealPlanAssignment::class, 'replaced_by_assignment_id');
    }
}
