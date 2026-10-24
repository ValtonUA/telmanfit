<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\KitchenEquipment;

class KitchenEquipmentResource extends CatalogResource
{
    protected static ?string $model = KitchenEquipment::class;

    protected static ?string $modelLabel = 'кухонне обладнання';

    protected static ?string $pluralModelLabel = 'Кухонне обладнання';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKitchenEquipment::route('/'),
            'create' => Pages\CreateKitchenEquipment::route('/create'),
            'edit' => Pages\EditKitchenEquipment::route('/{record}/edit'),
        ];
    }
}
