<?php

namespace App\Filament\Resources\MealPlans;

use App\Domain\MealPlans\MealItemKind;
use App\Models\MealPlanVersion;
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
        return ['draft' => 'Чернетка', 'published' => 'Опубліковано · лише перегляд'][$state] ?? $state;
    }

    public static function portionLabel(MealItemKind $kind): string
    {
        return match ($kind) {
            MealItemKind::Ingredient => 'Інгредієнт',
            MealItemKind::LegacyRecipe => 'Рецепт — порція',
            default => $kind->value,
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('plan.title')->label('Раціон')->url(fn ($record) => MealPlanResource::getUrl('view', ['record' => $record->meal_plan_id])),
            TextEntry::make('id')->label('ID точної версії'),
            TextEntry::make('version_number')->label('Номер публікації')->placeholder('Ще не опублікована'),
            TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => self::statusLabel($state)),
            TextEntry::make('published_at')->label('Дата публікації')->placeholder('Ще не опублікована'),
        ])->columns(1);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('plan');
    }

    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return MealPlanResource::getUrl('index', [], $isAbsolute, $panel, $tenant);
    }

    public static function getPages(): array
    {
        return ['view' => Pages\ViewMealPlanVersion::route('/{record}'), 'day' => Pages\EditMealPlanDay::route('/{record}/days/{day}')];
    }
}
