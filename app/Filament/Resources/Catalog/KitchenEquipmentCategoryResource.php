<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\KitchenEquipmentCategory;

class KitchenEquipmentCategoryResource extends CatalogResource
{
    protected static ?string $model = KitchenEquipmentCategory::class;

    protected static ?string $modelLabel = 'категорія обладнання';

    protected static ?string $pluralModelLabel = 'Категорії обладнання';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKitchenEquipmentCategory::route('/'),
            'create' => Pages\CreateKitchenEquipmentCategory::route('/create'),
            'edit' => Pages\EditKitchenEquipmentCategory::route('/{record}/edit'),
        ];
    }
}
