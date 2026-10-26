<?php

namespace Tests\Unit;

use App\Services\Recipes\RecipeTokens;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipeTokensTest extends TestCase
{
    public function test_stable_identity_remap_and_actual_format_values(): void
    {
        $text = RecipeTokens::remap('Взяти {{ingredient:pending:row-3:quantity}} / {{ingredient:3:grams}}', ['pending:row-3' => 9, 3 => 9]);
        $this->assertSame('Взяти 1.00 test-can / 420.00 g', RecipeTokens::render($text, [9 => ['week' => ['quantity' => '1.00', 'uom' => 'test-can', 'grams' => '420.00']]], 'week'));
    }

    public function test_equipment_remaps_independently_and_renders_each_actual_format(): void
    {
        $text = RecipeTokens::remap('{{ingredient:3:grams}} / {{equipment:3:quantity}} / 20 хв при 180°C', [3 => 8], [3 => 9]);
        $rows = [8 => ['week' => ['grams' => '1050.00']]];
        $tools = [9 => ['week' => ['min_value' => '2.00', 'uom' => 'л', 'tip' => 'Порада', 'name' => 'Каструля']]];
        $this->assertSame('1050.00 g / 2.00 л / 20 хв при 180°C', RecipeTokens::render($text, $rows, 'week', $tools));
        $tools[9]['week']['min_value'] = null;
        $this->assertSame('Без числової вимоги', RecipeTokens::render('{{equipment:9:quantity}}', [], 'week', $tools));
    }

    public function test_unknown_equipment_attribute_and_malformed_timer_block_rendering(): void
    {
        foreach (['{{equipment:9:temperature}}', '{{timer:1:seconds}}', '{{equipment:9:quantity}', '{{equipment:9:quantity}}'] as $token) {
            try {
                RecipeTokens::render($token, [], 'single');
                $this->fail('Token must fail: '.$token);
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_deleted_or_unknown_tokens_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        RecipeTokens::render('{{ingredient:99:quantity}}', [], 'week');
    }
}
