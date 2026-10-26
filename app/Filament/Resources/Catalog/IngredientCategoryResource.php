<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\IngredientCategory;

class IngredientCategoryResource extends CatalogResource
{
    protected static ?string $model = IngredientCategory::class;

    protected static ?string $modelLabel = 'категорія продуктів';

    protected static ?string $pluralModelLabel = 'Категорії продуктів';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIngredientCategory::route('/'),
            'create' => Pages\CreateIngredientCategory::route('/create'),
            'edit' => Pages\EditIngredientCategory::route('/{record}/edit'),
        ];
    }
}
