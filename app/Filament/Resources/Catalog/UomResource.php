<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\Uom;

class UomResource extends CatalogResource
{
    protected static ?string $model = Uom::class;

    protected static ?string $modelLabel = 'одиниця вимірювання';

    protected static ?string $pluralModelLabel = 'Одиниці вимірювання';

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUom::route('/'),
            'create' => Pages\CreateUom::route('/create'),
            'edit' => Pages\EditUom::route('/{record}/edit'),
        ];
    }
}
