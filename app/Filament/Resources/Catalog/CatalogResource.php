<?php

namespace App\Filament\Resources\Catalog;

use App\Models\Catalog\Ingredient;
use App\Services\Catalog\CatalogWriter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class CatalogResource extends Resource
{
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Харчові довідники';

    public static function canViewAny(): bool
    {
        return CatalogWriter::allowed(Filament::auth()->user());
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $model = new (static::getModel());
        $fields = [];
        foreach ($model->getFillable() as $field) {
            $fields[] = match ($field) {
                'ingredient_category_id' => static::category($field, 'ingredient_categories'),
                'kitchen_equipment_category_id' => static::category($field, 'kitchen_equipment_categories'),
                'kind' => Select::make($field)->label('Тип одиниці')->options(static::kinds())->required(),
                'code' => TextInput::make($field)->label('Код')->required()
                    ->disabled(fn (?Model $record) => $record !== null)->dehydrated()
                    ->helperText('Після створення код незмінний. g — маса, serving — порція; нові одиниці вводьте лише за погодженим довідником.'),
                default => TextInput::make($field)->label(static::label($field))->required()
                    ->inputMode(str_ends_with($field, '_per_100g') ? 'decimal' : 'text')
                    ->helperText(str_ends_with($field, '_per_100g') ? 'На 100 г. Від 0 до 99999.99, крапка, максимум 2 десяткові знаки. Без коми та розділювачів тисяч.' : null),
            };
        }
        if ($model instanceof Ingredient) {
            $fields[] = Repeater::make('conversions')->label('Додаткові одиниці продукту')->schema([
                Select::make('uom_id')->label('Одиниця')->options(fn () => DB::table('uoms')->where('code', '<>', 'g')->orderBy('label')->pluck('label', 'id')->all())
                    ->searchable()->required()->native(false),
                TextInput::make('grams_per_uom')->label('Грамів в одиниці')->inputMode('decimal')->required(),
                Toggle::make('is_default')->label('За замовчуванням')->default(false),
            ])->defaultItems(0)->reorderable(false)->deletable(true)
                ->deleteAction(fn (Action $action) => $action->visible(function (array $arguments, Repeater $component): bool {
                    $record = $component->getRecord();
                    $uom = $component->getRawState()[$arguments['item']]['uom_id'] ?? null;

                    return ! $record?->exists || ! DB::table('ingredient_uoms')->where('ingredient_id', $record->getKey())->where('uom_id', $uom)->exists();
                }))->columns(1)->columnSpanFull()
                ->addActionLabel('Додати одиницю')->helperText('Грам доступний неявно для кожного продукту, фактор 1. Додатковий фактор: 0.01–99999.99, крапка, до 2 знаків. Максимум одна одиниця за замовчуванням. Видалення збережених конверсій заборонено.');
        }

        return $schema->components($fields)->columns(1);
    }

    private static function category(string $field, string $table): Select
    {
        return Select::make($field)->label('Категорія (необов’язково)')->searchable()->native(false)
            ->options(fn () => DB::table($table)->orderBy('name')->pluck('name', 'id')->all())
            ->placeholder('Без категорії')->helperText('Якщо довідник порожній, спочатку створіть погоджену категорію у відповідному розділі.');
    }

    public static function kinds(): array
    {
        return ['mass' => 'Маса', 'volume' => 'Об’єм', 'count' => 'Кількість', 'serving' => 'Порція', 'other' => 'Інше'];
    }

    private static function label(string $field): string
    {
        return ['name' => 'Назва', 'label' => 'Позначення', 'code' => 'Код', 'kind' => 'Тип одиниці',
            'kcal_per_100g' => 'Калорійність, ккал / 100 г', 'protein_per_100g' => 'Білки, г / 100 г',
            'fat_per_100g' => 'Жири, г / 100 г', 'carbs_per_100g' => 'Вуглеводи, г / 100 г'][$field] ?? $field;
    }

    public static function table(Table $table): Table
    {
        $model = new (static::getModel());
        $columns = [TextColumn::make('id')->label('ID')->sortable()];
        $filters = [];
        foreach ($model->getFillable() as $field) {
            if (str_ends_with($field, '_category_id')) {
                $source = $field === 'ingredient_category_id' ? 'ingredient_categories' : 'kitchen_equipment_categories';
                $filters[] = SelectFilter::make($field)->label('Категорія')->options(fn () => DB::table($source)->orderBy('name')->pluck('name', 'id')->all());
                $columns[] = TextColumn::make($field)->label('Категорія')->formatStateUsing(fn ($state) => DB::table($source)->where('id', $state)->value('name'))->placeholder('Без категорії')->wrap();
            } else {
                $column = TextColumn::make($field)->label(static::label($field))->sortable()->wrap();
                if (in_array($field, ['name', 'code', 'label'], true)) {
                    $column->searchable();
                } elseif ($field === 'kind') {
                    $column->formatStateUsing(fn ($state) => static::kinds()[$state] ?? $state);
                    $filters[] = SelectFilter::make('kind')->label('Тип одиниці')->options(static::kinds());
                } else {
                    $column->visibleFrom('md');
                }
                $columns[] = $column;
            }
        }

        return $table->columns($columns)->filters($filters)->recordActions([EditAction::make()])
            ->emptyStateHeading('Довідник порожній або немає результатів')
            ->emptyStateDescription('Створіть погоджені значення вручну або змініть пошук і фільтри. Видалення довідників недоступне.')
            ->stackedOnMobile()->defaultSort('id', 'desc');
    }
}
