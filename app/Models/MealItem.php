<?php

namespace App\Models;

use App\Models\Catalog\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealItem extends Model
{
    public $timestamps = false;

    protected $table = 'meal_items';

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'resolved_quantity_grams' => 'decimal:2', 'sort_order' => 'integer', 'recipe_version_id' => 'integer', 'recipe_batch_format_id' => 'integer', 'recipe_portion_divisor' => 'integer'];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
