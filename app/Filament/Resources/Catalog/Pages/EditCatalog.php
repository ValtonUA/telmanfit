<?php

namespace App\Filament\Resources\Catalog\Pages;

use App\Filament\Resources\Catalog\Concerns\MapsCatalogValidation;
use App\Models\Catalog\Ingredient;
use App\Services\Catalog\CatalogWriter;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

abstract class EditCatalog extends EditRecord
{
    use MapsCatalogValidation;

    #[Locked]
    public string $catalogRevision = '';

    public function getTitle(): string
    {
        return 'Редагування: '.$this->getResource()::getPluralModelLabel();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $writer = app(CatalogWriter::class);
        // Один snapshot для даних форми й CAS, без вікна між двома читаннями.
        $snapshot = $writer->snapshot($this->getRecord());
        $this->catalogRevision = hash('sha256', json_encode($snapshot));
        if ($this->getRecord() instanceof Ingredient) {
            $data['conversions'] = array_map(fn ($r) => array_intersect_key($r, array_flip(['uom_id', 'grams_per_uom', 'is_default'])), $snapshot['conversions']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $writer = app(CatalogWriter::class);
            $saved = $this->withCatalogValidation(fn () => $writer->save(Filament::auth()->user(), $record, $data, $this->catalogRevision));
            // Revision читається до release row locks, не приймає паралельний чужий запис.
            $this->catalogRevision = $writer->revision($saved);

            return $saved;
        });
    }
}
