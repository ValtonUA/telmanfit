<?php

namespace App\Filament\Actions;

use App\Services\Catalog\CatalogWriter;
use App\Services\Catalog\RecipeCoverWriter;
use App\Support\Presentation\PublicImageUrl;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

/** Спільна metadata дія каталогу, незалежна від активації batch editor. */
final class RecipeCoverAction
{
    public static function make(?int $version = null): Action
    {
        return Action::make('recipeCover')->label('Обкладинка рецепта')->modalSubmitActionLabel('Зберегти')->color('gray')
            ->authorize(fn () => CatalogWriter::allowed(auth()->user()))
            ->fillForm(function () use ($version): array {
                $id = $version ? DB::table('recipe_versions')->where('id', $version)->value('recipe_id') : null;

                return $id ? self::data((int) $id) + ['recipe_id' => $id] : [];
            })
            ->schema([
                Select::make('recipe_id')->label('Логічний рецепт')->required()->searchable()->native(false)->live()
                    ->disabled($version !== null)->dehydrated()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('recipes')->where('title', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($search, 0, 150)).'%')->orderBy('id')->limit(30)->get()->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.$r->title])->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('recipes')->where('id', $value)->value('title'))
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $data = $state ? self::data((int) $state) : ['image_url' => null, 'expected' => null];
                        foreach ($data as $key => $value) {
                            $set($key, $value);
                        }
                    }),
                Hidden::make('expected')->required(),
                TextInput::make('image_url')->label('Фото: публічний HTTPS URL')->nullable()->maxLength(2048)
                    ->rules([new PublicImageUrl])->helperText('Спільне фото всіх версій. Вставте посилання з дозволеного домену або очистьте поле.'),
            ])->action(function (array $data) use ($version): void {
                if ($version !== null) {
                    abort_unless((int) DB::table('recipe_versions')->where('id', $version)->value('recipe_id') === (int) $data['recipe_id'], 403);
                }
                app(RecipeCoverWriter::class)->save(auth()->user(), (int) $data['recipe_id'], $data['image_url'] ?? null, $data['expected']);
                Notification::make()->success()->title('Обкладинку рецепта збережено')->send();
            });
    }

    private static function data(int $id): array
    {
        return ['image_url' => DB::table('recipes')->where('id', $id)->value('image_url'),
            'expected' => app(RecipeCoverWriter::class)->token(auth()->user(), $id)];
    }
}
