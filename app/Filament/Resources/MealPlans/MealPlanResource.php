<?php

namespace App\Filament\Resources\MealPlans;

use App\Models\Client;
use App\Models\MealPlan;
use App\Support\Presentation\MealPlanDisplay;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MealPlanResource extends Resource
{
    protected static ?string $model = MealPlan::class;

    protected static ?string $modelLabel = 'раціон';

    protected static ?string $pluralModelLabel = 'Раціони';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Назва')->required(),
            Select::make('week_start_weekday')->label('Початок тижня')->options(MealPlanDisplay::WEEKDAYS)->default(1)->required()
                ->helperText('Порядок днів у шаблоні; значення незмінне після створення. Це не дата призначення клієнтові.'),
            Select::make('owner_client_id')->label('Власник (необов’язково)')
                ->options(fn () => Client::with('user')->orderBy('id')->get()->mapWithKeys(fn (Client $client) => [
                    $client->id => '#'.$client->id.' — '.($client->user?->full_name ?: 'Без акаунта'),
                ])->all())->searchable()->native(false)->wrapOptionLabels()
                ->placeholder('Універсальний раціон')->helperText('Лише наявна картка клієнта. Це не призначення й не активація супроводу.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID')->sortable(),
            TextColumn::make('title')->label('Назва')->searchable()->wrap(),
            TextColumn::make('owner_client_id')->label('Власник')->formatStateUsing(fn ($state) => 'Клієнт #'.$state)->placeholder('Універсальний')->wrap(),
            TextColumn::make('versions.status')->label('Статуси версій')->badge()->formatStateUsing(fn ($state) => MealPlanVersionResource::statusLabel($state)),
            TextColumn::make('versions_count')->counts('versions')->label('Версій'),
        ])->filters([
            Filter::make('personal')->label('Персональні')->query(fn (Builder $query) => $query->whereNotNull('owner_client_id')),
            Filter::make('global')->label('Універсальні')->query(fn (Builder $query) => $query->whereNull('owner_client_id')),
        ])->recordActions([ViewAction::make()])->stackedOnMobile()->defaultSort('id', 'desc')
            ->emptyStateHeading('Раціонів немає')->emptyStateDescription('Створіть раціон з порожньою чернеткою або змініть пошук.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label('Назва'),
            TextEntry::make('week_start_weekday')->label('Початок тижня')->formatStateUsing(fn ($state) => MealPlanDisplay::WEEKDAYS[$state]),
            TextEntry::make('owner_client_id')->label('Власник')->formatStateUsing(fn ($state) => 'Клієнт #'.$state)->placeholder('Універсальний'),
            RepeatableEntry::make('versions')->label('Точні версії')->schema([
                TextEntry::make('id')->label('ID версії')->url(fn ($record) => MealPlanVersionResource::getUrl('view', ['record' => $record])),
                TextEntry::make('version_number')->label('Номер публікації')->placeholder('Ще не опублікована'),
                TextEntry::make('status')->label('Статус')->badge()->formatStateUsing(fn ($state) => MealPlanVersionResource::statusLabel($state)),
            ])->contained()->columnSpanFull(),
        ])->columns(1);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['versions' => fn ($query) => $query->orderBy('id')]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMealPlans::route('/'), 'create' => Pages\CreateMealPlan::route('/create'), 'view' => Pages\ViewMealPlan::route('/{record}')];
    }
}
