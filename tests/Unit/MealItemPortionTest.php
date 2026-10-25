<?php

namespace Tests\Unit;

use App\Domain\MealPlans\MealItemPortion;
use DomainException;
use PHPUnit\Framework\TestCase;

class MealItemPortionTest extends TestCase
{
    public function test_decimal_math_rounds_only_after_consumed_fraction(): void
    {
        $this->assertSame(['kcal' => '0.000001', 'protein' => '0.166667', 'fat' => '16666666.666667', 'carbs' => '0.000000'],
            MealItemPortion::nutrition(['kcal' => '0.000003', 'protein' => '1.000000', 'fat' => '99999999.999999', 'carbs' => '0.00'], '0.50', 3));
        $this->assertSame('16.665000', MealItemPortion::nutrition(array_fill_keys(['kcal', 'protein', 'fat', 'carbs'], '33.33'), '0.5', 1)['kcal']);
    }

    public function test_invalid_decimal_data_fails_explicitly(): void
    {
        foreach (['NaN', 'Infinity', '-1', '', 1.2, null] as $value) {
            try {
                MealItemPortion::nutrition(array_fill_keys(['kcal', 'protein', 'fat', 'carbs'], $value), '0.50', 7);
                $this->fail('Невалідний nutrient');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['0', '-1', 'NaN', ''] as $quantity) {
            try {
                MealItemPortion::nutrition([], $quantity, 1);
                $this->fail('Невалідна кількість');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
