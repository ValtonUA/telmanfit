<?php

namespace App\Domain\MealPlans;

enum MealItemKind: string
{
    case Ingredient = 'ingredient';
    case LegacyRecipe = 'legacy_recipe';
    case BatchA = 'A/1';
    case BatchB = 'B/7';
    case BatchC3 = 'C/3';
    case BatchC4 = 'C/4';
}
