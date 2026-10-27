<?php

namespace App\Filament\Resources\Library;

use App\Filament\Resources\Library\Pages\CreateLibraryItem;
use App\Filament\Resources\Library\Pages\EditLibraryItem;
use App\Filament\Resources\Library\Pages\ListLibraryItems;
use App\Models\LibraryItem;
use App\Services\Catalog\CatalogWriter;
use App\Support\Library\ExternalContentUrl;
use App\Support\Presentation\PublicImageUrl;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LibraryItemResource extends Resource
{
    protected static ?string $model = LibraryItem::class;

    protected static ?string $modelLabel = 'матеріал';

    protected static ?string $pluralModelLabel = 'Корисні матеріали';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Контент';

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
        return $schema->components([
            Select::make('type')->label('Тип матеріалу')->options(LibraryItem::TYPES)->required()->native(false),
            TextInput::make('title')->label('Назва')->required()->maxLength(255),
            Textarea::make('summary')->label('Короткий опис')->rows(3)->maxLength(2000),
            TextInput::make('cover_image_url')->label('Обкладинка (HTTPS)')->nullable()
                ->maxLength(2048)->rules([new PublicImageUrl])
                ->helperText('Необов’язково. Лише публічний HTTPS з дозволеного домену.'),
            Textarea::make('content_markdown')->label('Текст у Markdown')->rows(12)
                ->helperText('Для статей та інструкцій. Підтримуються заголовки, списки, посилання й зображення з дозволених доменів. HTML не виконується.'),
            TextInput::make('external_url')->label('Зовнішнє HTTPS-посилання')
                ->maxLength(2048)->rules([new ExternalContentUrl])
                ->helperText('Обов’язкове для відео і плейлістів. Для статей/інструкцій можна використовувати замість власного тексту.'),
            Select::make('status')->label('Публікація')->options(LibraryItem::STATUSES)
                ->default('draft')->required()->native(false)
                ->helperText('Клієнти бачать лише опубліковані матеріали. Зняття з публікації приховує матеріал відразу.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Назва')->searchable()->sortable()->wrap(),
            TextColumn::make('type')->label('Тип')->badge()
                ->formatStateUsing(fn (string $state): string => LibraryItem::TYPES[$state] ?? $state),
            TextColumn::make('status')->label('Статус')->badge()
                ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray')
                ->formatStateUsing(fn (string $state): string => LibraryItem::STATUSES[$state] ?? $state),
            TextColumn::make('published_at')->label('Опубліковано')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
        ])->filters([
            SelectFilter::make('type')->label('Тип')->options(LibraryItem::TYPES),
            SelectFilter::make('status')->label('Статус')->options(LibraryItem::STATUSES),
        ])->recordActions([EditAction::make()])->defaultSort('id', 'desc')
            ->emptyStateHeading('Матеріалів поки немає')
            ->emptyStateDescription('Створіть статтю, відео, плейліст або інструкцію.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLibraryItems::route('/'),
            'create' => CreateLibraryItem::route('/create'),
            'edit' => EditLibraryItem::route('/{record}/edit'),
        ];
    }
}
