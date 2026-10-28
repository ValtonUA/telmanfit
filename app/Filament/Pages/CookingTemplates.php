<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Catalog\CatalogWriter;
use App\Services\Cooking\CookingEditor;
use App\Services\Cooking\CookingTemplates as Lifecycle;
use App\Support\Presentation\PublicImageUrl;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CookingTemplates extends Page
{
    protected static ?string $slug = 'cooking-templates/{version?}';

    protected static ?string $navigationLabel = 'Кухонні шаблони';

    protected static ?string $title = 'Кухонні шаблони';

    protected string $view = 'filament.cooking.editor';

    #[Locked]
    public ?int $version = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public int $selectionRevision = 0;

    public static function canAccess(): bool
    {
        return CatalogWriter::allowed(Filament::auth()->user());
    }

    public function mount(int|string|null $version = null): void
    {
        abort_unless(static::canAccess(), 403);
        if ($version !== null) {
            abort_unless(ctype_digit((string) $version), 404);
            $this->version = (int) $version;
            $this->reloadRevision();
        }
    }

    public function hydrate(): void
    {
        app(CookingEditor::class)->authorize($this->actor());
    }

    private function actor(): User
    {
        return Filament::auth()->user();
    }

    public function record(): ?object
    {
        return $this->version === null ? null : DB::table('cooking_template_versions as v')->join('cooking_templates as t', 't.id', '=', 'v.template_id')->where('v.id', $this->version)->select('v.*', 't.title')->firstOrFail();
    }

    public function versions(): Collection
    {
        return DB::table('cooking_template_versions as v')->join('cooking_templates as t', 't.id', '=', 'v.template_id')->orderByDesc('v.id')->select('v.*', 't.title')->get();
    }

    public function reloadRevision(): void
    {
        app(CookingEditor::class)->authorize($this->actor());
        $v = $this->record();
        $this->revision = (int) $v->revision;
        $this->selectionRevision = (int) (DB::table('cooking_selections')->where('template_id', $v->template_id)->where('mode', $v->mode)->where('menu_fingerprint', $v->menu_fingerprint)->value('revision') ?? 0);
    }

    private function command(callable $callback): void
    {
        app(CookingEditor::class)->authorize($this->actor());
        try {
            $callback();
            if ($this->version !== null) {
                $this->reloadRevision();
            }
            Notification::make()->title('Збережено')->success()->send();
        } catch (DeadlockException $e) {
            Notification::make()->title('Конфлікт змін. Оновіть сторінку та перевірте нову редакцію.')->danger()->persistent()->send();
        } catch (QueryException $e) {
            Notification::make()->title(in_array($e->getCode(), ['40001', '40P01', '55P03'], true) ? 'Конфлікт змін. Оновіть сторінку та перевірте нову редакцію.' : 'Команду відхилено. Перевірте сесії, посилання, порції та повноту розподілу.')->danger()->persistent()->send();
        } catch (HttpException $e) {
            if (! in_array($e->getStatusCode(), [409, 422], true)) {
                throw $e;
            }
            Notification::make()->title($e->getMessage() ?: 'Конфлікт змін. Оновіть сторінку.')->danger()->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [$this->createAction(), $this->reviewAction(), $this->publishAction(), $this->activateAction()];
    }

    public function createAction(): Action
    {
        return Action::make('create')->label('Новий шаблон')->schema($this->sourceFields())->action(function (array $data) {
            $this->command(function () use ($data) {
                $id = app(CookingEditor::class)->create($this->actor(), $data);
                $this->redirect(static::getUrl(['version' => $id]));
            });
        });
    }

    private function sourceFields(): array
    {
        return [TextInput::make('title')->label('Назва')->required()->maxLength(500),
            Select::make('source_plan_version_id')->label('Точна опублікована версія раціону')->options(fn () => DB::table('meal_plan_versions as v')->join('meal_plans as p', 'p.id', '=', 'v.meal_plan_id')->where('v.status', 'published')->orderByDesc('v.id')->get(['v.id', 'p.title'])->mapWithKeys(fn ($v) => [$v->id => $v->title.' · #'.$v->id]))->searchable()->required(),
            TextInput::make('source_week')->label('Повний тиждень')->integer()->minValue(1)->maxValue(4681)->default(1)->required(),
            Select::make('mode')->label('Кількість сесій')->options([1 => '1 сесія', 2 => '2 сесії', 7 => '7 сесій'])->default(1)->required()];
    }

    public function nextAction(): Action
    {
        return Action::make('next')->label('Нова редакція')->visible(fn () => $this->record()?->status === 'published')->schema($this->sourceFields())
            ->fillForm(fn () => (array) $this->record())->action(function (array $data) {
                $this->command(function () use ($data) {
                    $id = app(CookingEditor::class)->create($this->actor(), $data, $this->version);
                    $this->redirect(static::getUrl(['version' => $id]));
                });
            });
    }

    public function reviewAction(): Action
    {
        return Action::make('review')->label('Підтвердити безпеку')->modalSubmitActionLabel('Підтвердити')->visible(fn () => $this->record()?->status === 'draft')->schema([
            Checkbox::make('suitability')->label('Підтверджую придатність усіх страв та інгредієнтів')->accepted(),
            Checkbox::make('safety')->label('Перевірено безпеку, фасування, охолодження, зберігання та розігрівання')->accepted(),
            Checkbox::make('manual_parameters')->label('Вода, обладнання, температура та час перевірені автором')->accepted(),
            Checkbox::make('no_process_water_tokens')->label('Технологічна вода не підставляється токенами')->accepted(),
        ])->action(fn (array $data) => $this->command(fn () => app(Lifecycle::class)->review($this->actor(), $this->version, $this->revision, $data)));
    }

    public function publishAction(): Action
    {
        return Action::make('publish')->label('Опублікувати')->modalSubmitActionLabel('Опублікувати')->visible(fn () => $this->record()?->status === 'draft')->requiresConfirmation()
            ->modalDescription('Поточна збережена й підтверджена редакція стане незмінною. Активація виконується окремо.')
            ->action(fn () => $this->command(fn () => app(Lifecycle::class)->publish($this->actor(), $this->version, $this->revision)));
    }

    public function activateAction(): Action
    {
        return Action::make('activate')->label('Активувати цю версію')->modalSubmitActionLabel('Активувати')->visible(fn () => $this->record()?->status === 'published')->requiresConfirmation()
            ->modalDescription('Явно замінити чинну версію цього шаблону, меню та режиму.')->action(fn () => $this->command(fn () => app(Lifecycle::class)->select($this->actor(), $this->version, $this->selectionRevision)));
    }

    public function sessions(): Collection
    {
        return DB::table('cooking_sessions')->where('version_id', $this->version)->orderBy('position')->get();
    }

    public function sessionAction(): Action
    {
        return Action::make('session')->label('Сесія / етапи')->visible(fn () => $this->record()?->status === 'draft')
            ->fillForm(function (array $arguments) {
                if (! isset($arguments['id'])) {
                    return ['day' => 1, 'position' => $this->sessions()->count() + 1, 'estimated_seconds' => 3600, 'stages' => []];
                }
                $s = DB::table('cooking_sessions')->where('version_id', $this->version)->where('id', $arguments['id'])->firstOrFail();
                $data = (array) $s;
                $data['stages'] = json_decode($s->content, true)['stages'];
                foreach ($data['stages'] as &$stage) {
                    foreach ($stage['blocks'] as &$block) {
                        if ($block['type'] === 'action') {
                            $block['parts'] = array_map(fn ($part) => is_string($part) ? ['kind' => 'text', 'text' => $part] : ['kind' => 'token', 'metric' => $part['metric'], 'sources' => array_map(fn ($s) => $s['output'].':'.$s['row'], $part['sources'])], $block['parts']);
                        }
                    }
                }

                return $data;
            })->schema(fn (array $arguments) => $this->sessionFields(isset($arguments['id']) ? (int) $arguments['id'] : null))
            ->action(function (array $data, array $arguments) {
                $this->command(function () use ($data, $arguments) {
                    $data['content'] = $this->compileContent($data['stages']);
                    app(CookingEditor::class)->session($this->actor(), $this->version, $this->revision, $data, isset($arguments['id']) ? (int) $arguments['id'] : null);
                });
            });
    }

    private function sessionFields(?int $session): array
    {
        $id = fn () => Hidden::make('id')->default(fn () => (string) Str::uuid());

        return [TextInput::make('day')->label('День приготування')->integer()->minValue(1)->maxValue(7)->required(),
            TextInput::make('position')->label('Порядок сесії')->integer()->minValue(1)->required(),
            TextInput::make('estimated_seconds')->label('Авторський час, секунд')->integer()->minValue(1)->required(),
            Textarea::make('introduction')->label('Вступ'), Textarea::make('equipment')->label('Обладнання (вручну)'),
            Repeater::make('stages')->label('Етапи')->reorderable()->reorderableWithButtons()->cloneable()->cloneAction(fn (Action $action) => $this->cloneItem($action))->collapsible()->itemLabel(fn (array $state) => $state['title'] ?? 'Етап')->schema([
                $id(), TextInput::make('title')->label('Назва етапу')->required(),
                Repeater::make('blocks')->label('Блоки')->reorderable()->reorderableWithButtons()->cloneable()->cloneAction(fn (Action $action) => $this->cloneItem($action))->collapsible()->schema([
                    $id(), Select::make('type')->label('Тип')->options(['action' => 'Дія', 'hint' => 'Підказка', 'image' => 'Фотографія', 'timer' => 'Таймер'])->default('action')->live()->required(),
                    Textarea::make('text')->label('Підказка')->visible(fn (Get $get) => $get('type') === 'hint'),
                    TextInput::make('url')->label('HTTPS URL фото')->visible(fn (Get $get) => $get('type') === 'image'),
                    TextInput::make('alt')->label('Опис фото')->visible(fn (Get $get) => $get('type') === 'image'),
                    TextInput::make('label')->label('Назва таймера')->visible(fn (Get $get) => $get('type') === 'timer'),
                    TextInput::make('seconds')->label('Секунди')->integer()->minValue(1)->maxValue(604800)->visible(fn (Get $get) => $get('type') === 'timer'),
                    Textarea::make('message')->label('Повідомлення таймера')->visible(fn (Get $get) => $get('type') === 'timer'),
                    Repeater::make('parts')->label('Текст та кількості')->visible(fn (Get $get) => $get('type') === 'action')->schema([
                        Select::make('kind')->label('Частина дії')->options(['text' => 'Авторський текст', 'token' => 'Frozen кількість / явна сума'])->default('text')->live()->required(),
                        Textarea::make('text')->label('Текст (вода, температура, час — вручну)')->visible(fn (Get $get) => $get('kind') === 'text'),
                        Select::make('metric')->label('Метрика')->options(['grams' => 'Грами', 'quantity' => 'Кількість у frozen одиниці'])->default('grams')->visible(fn (Get $get) => $get('kind') === 'token'),
                        Select::make('sources')->label('Джерела: кілька = явна сума сумісних значень')->multiple()->options(fn () => $this->sources($session))->visible(fn (Get $get) => $get('kind') === 'token'),
                    ])->minItems(1),
                ])->minItems(1),
            ])->minItems(1)];
    }

    private function cloneItem(Action $action): Action
    {
        return $action->action(function (array $arguments, Repeater $component): void {
            $items = $component->getRawState() ?? [];
            abort_unless(isset($items[$arguments['item']]), 422);
            $copy = $items[$arguments['item']];
            $renew = function (array &$value) use (&$renew): void {
                foreach ($value as $key => &$child) {
                    if ($key === 'id') {
                        $child = (string) Str::uuid();
                    } elseif (is_array($child)) {
                        $renew($child);
                    }
                }
            };
            $renew($copy);
            $items[$component->generateUuid()] = $copy;
            $component->rawState($items);
            $component->collapsed(false, shouldMakeComponentCollapsible: false);
            $component->callAfterStateUpdated();
            $component->shouldPartiallyRenderAfterActionsCalled() ? $component->partiallyRender() : null;
        });
    }

    public function sources(?int $session): array
    {
        if ($session === null) {
            return [];
        }

        return DB::table('cooking_outputs as o')->join('recipe_ingredients as r', 'r.recipe_version_id', '=', 'o.recipe_version_id')->where('o.version_id', $this->version)->where('o.session_id', $session)
            ->get(['o.id', 'r.id as row', 'r.ingredient_snapshot'])->mapWithKeys(fn ($r) => [$r->id.':'.$r->row => 'Приготування #'.$r->id.' · рядок #'.$r->row.' · '.(json_decode($r->ingredient_snapshot ?? '{}', true)['name'] ?? 'Інгредієнт')])->all();
    }

    public function compileContent(array $stages): array
    {
        $seen = [];
        $identity = function ($id) use (&$seen) {
            if (! is_string($id) || isset($seen[$id])) {
                $id = (string) Str::uuid();
            }
            $seen[$id] = true;

            return $id;
        };
        $result = [];
        foreach ($stages as $stage) {
            $s = ['id' => $identity($stage['id'] ?? null), 'title' => $stage['title'], 'blocks' => []];
            foreach ($stage['blocks'] as $block) {
                $b = ['id' => $identity($block['id'] ?? null), 'type' => $block['type']];
                foreach (match ($b['type']) {
                    'hint' => ['text'], 'image' => ['url', 'alt'], 'timer' => ['label', 'seconds', 'message'], default => []
                } as $field) {
                    $b[$field] = $field === 'seconds' ? (int) $block[$field] : ($block[$field] ?? '');
                }
                if ($b['type'] === 'action') {
                    $b['parts'] = array_values(array_map(fn ($part) => $part['kind'] === 'text' ? ($part['text'] ?? '') : ['metric' => $part['metric'], 'sources' => array_map(function ($key) {
                        [$output, $row] = explode(':', $key);

                        return ['output' => (int) $output, 'row' => (int) $row];
                    }, $part['sources'] ?? [])], array_values($block['parts'])));
                }
                $s['blocks'][] = $b;
            }
            $result[] = $s;
        }

        return ['schema_version' => 1, 'stages' => $result];
    }

    public function outputAction(): Action
    {
        return Action::make('output')->label('Додати приготування')->visible(fn () => $this->record()?->status === 'draft')->schema([
            Select::make('session')->label('Сесія')->options(fn () => $this->sessions()->mapWithKeys(fn ($s) => [$s->id => 'День '.$s->day.' · #'.$s->id]))->required(),
            Select::make('selector')->label('Точний рецепт і формат')->options(fn () => collect(app(CookingEditor::class)->selectors($this->version))->mapWithKeys(fn ($s, $k) => [$k => ($s['recipe_snapshot']['version']['title'] ?? 'Рецепт').' · v#'.$s['recipe_version_id'].' · '.$this->selectorLabel($s)]))->required(),
        ])->action(fn (array $data) => $this->command(fn () => app(CookingEditor::class)->output($this->actor(), $this->version, $this->revision, (int) $data['session'], $data['selector'])));
    }

    public function selectorLabel(array $slot): string
    {
        $format = collect($slot['recipe_snapshot']['formats'])->firstWhere('id', $slot['format_id']);
        $code = ['single' => 'A', 'week' => 'B', 'half_week' => 'C'][$format['code'] ?? ''] ?? 'legacy';

        return $code.'/'.$slot['divisor'].' · format #'.($slot['format_id'] ?? 'NULL');
    }

    public function allocationAction(): Action
    {
        return Action::make('allocation')->label('Розподілити')->visible(fn () => $this->record()?->status === 'draft')->schema([
            Select::make('output')->label('Приготування (порожнє — зняти розподіл)')->options(fn () => DB::table('cooking_outputs')->where('version_id', $this->version)->get()->mapWithKeys(fn ($o) => [$o->id => '#'.$o->id.' · рецепт #'.$o->recipe_version_id.' / '.$o->portion_divisor])),
        ])->action(fn (array $data, array $arguments) => $this->command(fn () => app(CookingEditor::class)->allocate($this->actor(), $this->version, $this->revision, (int) $arguments['day'], (int) $arguments['meal'], (int) $arguments['item'], filled($data['output'] ?? null) ? (int) $data['output'] : null)));
    }

    public function removeAction(): Action
    {
        return Action::make('remove')->label('Видалити')->color('danger')->requiresConfirmation()->visible(fn () => $this->record()?->status === 'draft')
            ->action(fn (array $arguments) => $this->command(fn () => app(CookingEditor::class)->remove($this->actor(), $this->version, $this->revision, $arguments['kind'], (int) $arguments['id'])));
    }

    public function preview(): array
    {
        $sessions = [];
        foreach ($this->sessions() as $s) {
            $content = json_decode($s->content, true);
            foreach ($content['stages'] as &$stage) {
                foreach ($stage['blocks'] as &$block) {
                    if ($block['type'] === 'action') {
                        $block['parts'] = array_map(function ($part) use ($s) {
                            if (is_string($part)) {
                                return $part;
                            }
                            $token = app(CookingEditor::class)->token($s->id, $part);

                            return $token['value'].' '.$token['unit_label'];
                        }, $block['parts']);
                    }
                    if ($block['type'] === 'image') {
                        $block['url'] = app(PublicImageUrl::class)->safe($block['url']);
                    }
                }
            }
            $sessions[] = ['session' => $s, 'content' => $content];
        }

        return $sessions;
    }
}
