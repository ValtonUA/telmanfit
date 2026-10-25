<?php

namespace App\Filament\Resources\MealPlans;

use App\Models\MealPlanVersion;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MealPlanVersionResource extends Resource
{
    protected static ?string $model = MealPlanVersion::class;

    protected static ?string $slug = 'meal-plan-versions';

    protected static ?string $modelLabel = 'версія раціону';

    protected static ?string $pluralModelLabel = 'Версії раціонів';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static bool $shouldRegisterNavigation = false;

    public static function statusLabel(string $state): string
    {
        return ['draft' => 'Чернетка', 'published' => 'Опублікована'][$state] ?? $state;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('plan.title')->label('Раціон')->url(fn ($record) => MealPlanResource::getUrl('view', ['record' => $record->meal_plan_id])),
            TextEntry::make('id')->label('ID точної версії'),
            TextEntry::make('version_number')->label('Номер публікації')->placeholder('Ще не опублікована'),
            TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state)),
            TextEntry::make('published_at')->label('Дата публікації')->placeholder('Ще не опублікована'),
            TextEntry::make('boundary')->label('Режим перегляду')->state(fn ($record) => $record->status === 'draft'
                ? 'Чернетка ще не готова до публікації. Додайте семиденний тиждень і налаштуйте прийоми. Позицій ще немає; їх редактор і Publish будуть окремими етапами.'
                : 'Опублікована версія — лише читання. Історія та призначення зберігаються.'),
            RepeatableEntry::make('days')->label('Дні → прийоми їжі → позиції')->placeholder('Порожня чернетка: днів ще немає.')->schema([
                TextEntry::make('week')->label('Тиждень')->state(fn ($record) => intdiv($record->day_number - 1, 7) + 1),
                TextEntry::make('day_number')->label('День'),
                RepeatableEntry::make('meals')->label('Прийоми їжі')->placeholder('Прийомів їжі ще немає.')->schema([
                    TextEntry::make('type.title')->label('Тип прийому їжі'),
                    TextEntry::make('title')->label('Назва прийому')->placeholder('Без додаткової назви'),
                    TextEntry::make('sort_order')->label('Порядок'),
                    RepeatableEntry::make('items')->label('Позиції')->placeholder('Позицій ще немає.')->schema([
                        TextEntry::make('food.label')->label('Позиція'),
                        TextEntry::make('food_id')->label('Food ID (точне історичне посилання)'),
                        TextEntry::make('food.food_type')->label('Тип позиції'),
                        TextEntry::make('quantity')->label('Кількість'),
                        TextEntry::make('uom.label')->label('Одиниця'),
                        TextEntry::make('resolved_quantity_grams')->label('Збережені грами')->placeholder('Не застосовується'),
                    ])->columns(1)->contained(),
                ])->columns(1)->contained(),
            ])->columns(1)->contained()->columnSpanFull(),
        ])->columns(1);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['plan', 'days' => fn ($q) => $q->orderBy('day_number'),
            'days.meals' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'days.meals.type',
            'days.meals.items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'days.meals.items.food.ingredient', 'days.meals.items.uom']);
    }

    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return MealPlanResource::getUrl('index', [], $isAbsolute, $panel, $tenant);
    }

    public static function getPages(): array
    {
        return ['view' => Pages\ViewMealPlanVersion::route('/{record}')];
    }
}
