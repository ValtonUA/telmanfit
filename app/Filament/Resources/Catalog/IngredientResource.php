<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\Ingredient;

class IngredientResource extends CatalogResource
{
    protected static ?string $model = Ingredient::class;

    protected static ?string $modelLabel = 'продукт';

    protected static ?string $pluralModelLabel = 'Продукти';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIngredient::route('/'),
            'create' => Pages\CreateIngredient::route('/create'),
            'edit' => Pages\EditIngredient::route('/{record}/edit'),
        ];
    }
}
