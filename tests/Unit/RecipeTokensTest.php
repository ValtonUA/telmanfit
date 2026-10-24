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

    public function test_deleted_or_unknown_tokens_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        RecipeTokens::render('{{ingredient:99:quantity}}', [], 'week');
    }
}
