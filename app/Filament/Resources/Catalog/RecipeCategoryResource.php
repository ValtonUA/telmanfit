<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\RecipeCategory;

class RecipeCategoryResource extends CatalogResource
{
    protected static ?string $model = RecipeCategory::class;

    protected static ?string $modelLabel = 'категорія рецептів';

    protected static ?string $pluralModelLabel = 'Категорії рецептів';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRecipeCategory::route('/'),
            'create' => Pages\CreateRecipeCategory::route('/create'),
            'edit' => Pages\EditRecipeCategory::route('/{record}/edit'),
        ];
    }
}
