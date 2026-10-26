<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Ingredient;
use App\Models\Catalog\IngredientCategory;
use App\Models\Catalog\KitchenEquipment;
use App\Models\Catalog\KitchenEquipmentCategory;
use App\Models\Catalog\RecipeCategory;
use App\Models\Catalog\Uom;
use App\Models\User;
use App\Support\Presentation\PublicImageUrl;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Єдиний writer UI каталогу: чинна схема, decimal strings, CAS і атомарність. */
final class CatalogWriter
{
    public static function allowed(?User $actor): bool
    {
        $fresh = $actor?->exists ? User::find($actor->id) : null;

        return $fresh?->role === User::ROLE_ADMIN && $fresh->canAccessPanel(Filament::getPanel('admin'));
    }

    public function snapshot(Model $record): array
    {
        $data = $record->getAttributes();
        if ($record instanceof Ingredient) {
            $data['conversions'] = DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->orderBy('uom_id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $data;
    }

    public function revision(Model $record): string
    {
        return hash('sha256', json_encode($this->snapshot($record)));
    }

    public function save(User $actor, Model $record, array $input, ?string $revision = null): Model
    {
        abort_unless(in_array($record::class, [Ingredient::class, IngredientCategory::class, RecipeCategory::class,
            Uom::class, KitchenEquipment::class, KitchenEquipmentCategory::class], true) && self::allowed($actor), 403);
        try {
            return DB::transaction(function () use ($actor, $record, $input, $revision) {
                DB::table('users')->where('id', $actor->id)->sharedLock()->first();
                abort_unless(self::allowed($actor), 403);
                // UOM writer бере ingredient locks перед UOM, як Refresh/Publish.
                if ($record->exists && $record instanceof Uom) {
                    $ids = DB::table('ingredient_uoms')->where('uom_id', $record->id)->pluck('ingredient_id');
                    $query = DB::table('ingredients')->orderBy('id');
                    if ($record->code !== 'g') {
                        $query->whereIn('id', $ids);
                    }
                    $query->lockForUpdate()->get();
                }
                if ($record->exists) {
                    $record = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                    if ($revision === null || ! hash_equals($this->revision($record), $revision)) {
                        throw ValidationException::withMessages(['catalog' => 'Довідник змінився. Перезавантажте форму та перегляньте зміни.']);
                    }
                }
                if ($record instanceof Ingredient && ($input['image_url'] ?? null) === '') {
                    $input['image_url'] = null;
                }
                $data = Validator::make($input, $this->rules($record))->validate();
                if ($record instanceof Uom) {
                    if ($record->exists && $data['code'] !== $record->code) {
                        throw ValidationException::withMessages(['code' => 'Код одиниці незмінний.']);
                    }
                    $kind = ['g' => 'mass', 'serving' => 'serving'][$data['code']] ?? null;
                    if ($kind !== null && $data['kind'] !== $kind) {
                        throw ValidationException::withMessages(['kind' => 'Службова одиниця має незмінну семантику g=mass, serving=serving.']);
                    }
                }
                $conversions = $data['conversions'] ?? [];
                unset($data['conversions']);
                if ($record instanceof Ingredient) {
                    $this->validateConversions($record, $conversions);
                    if (! $record->exists) {
                        $record->food_id = DB::table('foods')->insertGetId(['food_type' => 'ingredient']);
                        $record->food_type = 'ingredient';
                    }
                }
                $record->fill($data)->save();
                if ($record instanceof Ingredient) {
                    // Спочатку зняти старий default, щоб partial UNIQUE дозволив перенесення.
                    DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->update(['is_default' => false]);
                    foreach ($conversions as $row) {
                        DB::table('ingredient_uoms')->updateOrInsert(['ingredient_id' => $record->id, 'uom_id' => $row['uom_id']],
                            ['grams_per_uom' => $row['grams_per_uom'], 'is_default' => $row['is_default'] ?? false]);
                    }
                }
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');

                return $record->refresh();
            });
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? '', ['23505', '23503', '40001', '40P01'], true)) {
                throw ValidationException::withMessages(['catalog' => 'Конфлікт довідника або посилань. Перезавантажте форму й перевірте значення.']);
            }
            throw $e;
        }
    }

    public function rules(Model $record): array
    {
        $rules = [];
        foreach ($record->getFillable() as $field) {
            $rules[$field] = match (true) {
                $field === 'image_url' => ['nullable', new PublicImageUrl],
                str_ends_with($field, '_per_100g') => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?\z/'],
                str_ends_with($field, '_category_id') => ['nullable', 'integer'],
                $field === 'kind' => ['required', Rule::in(['mass', 'volume', 'count', 'serving', 'other'])],
                default => ['required', 'string'],
            };
        }
        // Імена FK не утворюються простим pluralize category.
        foreach (['ingredient_category_id' => 'ingredient_categories', 'kitchen_equipment_category_id' => 'kitchen_equipment_categories'] as $field => $table) {
            if (isset($rules[$field])) {
                $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')];
            }
        }
        $unique = $record instanceof Uom ? 'code' : 'name';
        if (! $record instanceof Ingredient) {
            $rules[$unique][] = Rule::unique($record->getTable(), $unique)->ignore($record->getKey());
        } else {
            $rules['conversions'] = ['present', 'array'];
            $rules['conversions.*.uom_id'] = ['required', 'integer', 'distinct', Rule::exists('uoms', 'id')->whereNot('code', 'g')];
            $rules['conversions.*.grams_per_uom'] = ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?\z/', 'not_in:0,0.0,0.00'];
            $rules['conversions.*.is_default'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function validateConversions(Model $record, array $rows): void
    {
        if (count(array_filter($rows, fn ($r) => $r['is_default'] ?? false)) > 1) {
            throw ValidationException::withMessages(['conversions' => 'Оберіть не більше однієї одиниці за замовчуванням.']);
        }
        if ($record->exists && DB::table('ingredient_uoms')->where('ingredient_id', $record->id)->whereNotIn('uom_id', array_column($rows, 'uom_id'))->exists()) {
            throw ValidationException::withMessages(['conversions' => 'Видалення або заміна збережених конверсій у v1 заборонені. Можна змінити фактор чи default або додати одиницю.']);
        }
        DB::table('ingredient_uoms')->where('ingredient_id', $record->id ?? 0)->orderBy('uom_id')->lockForUpdate()->get();
        DB::table('uoms')->whereIn('id', array_column($rows, 'uom_id'))->orderBy('id')->sharedLock()->get();
    }
}
