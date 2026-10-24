<?php

namespace Tests\Unit;

use App\Models\ClientMealPlanAssignment;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPlan;
use App\Models\MealPlanDay;
use App\Models\MealPlanVersion;
use App\Models\MealType;
use PHPUnit\Framework\TestCase;

class MealPlanModelsTest extends TestCase
{
    public function test_keys_timestamps_and_mass_assignment_boundary(): void
    {
        foreach ([new MealPlan, new MealPlanVersion, new MealPlanDay, new Meal, new MealType, new MealItem, new ClientMealPlanAssignment] as $model) {
            $this->assertSame('id', $model->getKeyName());
            $this->assertSame('int', $model->getKeyType());
            $this->assertTrue($model->getIncrementing());
            $this->assertSame($model instanceof MealPlan ? ['title'] : [], $model->getFillable());
            foreach (['id', 'status', 'version_number', 'published_at', 'replaced_by_assignment_id', 'food_id', 'owner_client_id', 'parent_meal_plan_id', 'meal_plan_id'] as $field) {
                $this->assertFalse($model->isFillable($field));
            }
            if ($model instanceof MealPlan || $model instanceof MealPlanVersion || $model instanceof ClientMealPlanAssignment) {
                $this->assertTrue($model->usesTimestamps());
                $this->assertNull($model->getUpdatedAtColumn());
            } else {
                $this->assertFalse($model->usesTimestamps());
            }
        }
    }
}
