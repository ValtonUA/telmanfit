<x-filament-panels::page>
    @if (!$this->version)
        <x-filament::section heading="Версії кухонних шаблонів">
            <div class="space-y-4">
            @forelse ($this->versions() as $v)
                <p><a class="underline" href="{{ static::getUrl(['version' => $v->id]) }}">{{ $v->title }} · #{{ $v->id }} · {{ $v->status === 'published' ? 'Опублікована' : 'Чернетка' }} · {{ $v->mode }} сесій</a></p>
            @empty
                <p>Створіть шаблон для повного тижня опублікованого раціону.</p>
            @endforelse
            </div>
        </x-filament::section>
    @else
        @php($v = $this->record())
        <x-filament::section :heading="$v->title">
            <div class="space-y-3 break-words">
                <p>Версія #{{ $v->id }} · редакція {{ $this->revision }} · {{ $v->status === 'draft' ? 'Чернетка' : 'Опублікована (незмінна)' }}</p>
                <p>Раціон #{{ $v->source_plan_version_id }} · тиждень {{ $v->source_week }} · {{ $v->mode }} сесій</p>
                <p>{{ $v->reviewed_revision === null ? 'Авторське підтвердження потрібне перед публікацією.' : 'Поточну збережену редакцію підтверджено автором.' }}</p>
                <p>Чинна версія цього слота: #{{ \Illuminate\Support\Facades\DB::table('cooking_selections')->where('template_id', $v->template_id)->where('mode', $v->mode)->where('menu_fingerprint', $v->menu_fingerprint)->value('version_id') ?? 'не обрана' }}</p>
                <p>Вода, обладнання, температура та час задаються автором. Режим не гарантує безпеки зберігання. Публікація й активація — окремі дії.</p>
                <div class="flex flex-wrap gap-3">@if ($v->status === 'draft') {{ $this->sessionAction }} {{ $this->outputAction }} @else {{ $this->nextAction }} @endif</div>
                <a class="underline" href="{{ static::getUrl() }}">Усі шаблони</a>
            </div>
        </x-filament::section>
        <x-filament::section heading="Приготування та залишки">
            <div class="space-y-4">
            @foreach (\Illuminate\Support\Facades\DB::table('cooking_outputs as o')->join('cooking_output_balances as b', 'b.id', '=', 'o.id')->join('cooking_sessions as s', 's.id', '=', 'o.session_id')->where('o.version_id', $v->id)->select('o.*', 's.day', 'b.cooked', 'b.allocated', 'b.left_over')->orderBy('o.id')->get() as $o)
                <div class="break-words"><p>Приготування #{{ $o->id }} · день {{ $o->day }} · рецепт #{{ $o->recipe_version_id }} · формат #{{ $o->format_id ?? 'legacy' }}</p>
                <p>Приготовлено: {{ $o->cooked }} · розподілено: {{ $o->allocated }} · залишок: {{ $o->left_over }}</p>
                @if ($v->status === 'draft') {{ ($this->removeAction)(['kind' => 'outputs', 'id' => $o->id]) }} @endif</div>
            @endforeach
            </div>
        </x-filament::section>
        <x-filament::section heading="Розподіл між прийомами їжі">
            <div class="space-y-4">
            @foreach (json_decode($v->menu_pattern, true)['items'] as $slot)
                @if ($slot['recipe_version_id'] !== null)
                    <div class="break-words"><p>День {{ $slot['day'] }} · прийом {{ $slot['meal_position'] }} · позиція {{ $slot['item_position'] }} · {{ $slot['recipe_snapshot']['version']['title'] ?? 'Рецепт' }} #{{ $slot['recipe_version_id'] }} · {{ $slot['quantity'] }} порцій</p>
                    <p>Приготування #{{ \Illuminate\Support\Facades\DB::table('cooking_allocations')->where('version_id', $v->id)->where('day', $slot['day'])->where('meal_position', $slot['meal_position'])->where('item_position', $slot['item_position'])->value('output_id') ?? 'не розподілено' }}</p>
                    @if ($v->status === 'draft') {{ ($this->allocationAction)(['day' => $slot['day'], 'meal' => $slot['meal_position'], 'item' => $slot['item_position']]) }} @endif</div>
                @endif
            @endforeach
            </div>
        </x-filament::section>
        <x-filament::section heading="Попередній перегляд збереженого мегарецепта">
            <p>Незбережені зміни форми сюди не включено. Підстановка кількостей не є перевіркою готовності або безпеки.</p>
            <div class="space-y-6 mt-4">
            @foreach ($this->preview() as $entry)
                @php($s = $entry['session'])
                <article class="space-y-3 break-words" wire:key="cooking-session-{{ $s->id }}">
                    <h2 class="font-bold">Сесія #{{ $s->id }} · день {{ $s->day }} · {{ $s->estimated_seconds }} секунд</h2>
                    <p class="whitespace-pre-wrap">{{ $s->introduction }}</p>
                    @if ($entry['equipment_items']->isNotEmpty())
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Обладнання з каталогу">
                            @foreach ($entry['equipment_items'] as $equipment)
                                <div class="space-y-2 rounded-xl border p-3">
                                    <p class="font-medium">{{ $equipment['name'] }}</p>
                                    @if ($equipment['image_url'])
                                        <img class="aspect-square w-full rounded-lg object-cover" src="{{ $equipment['image_url'] }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if (filled($s->equipment)) <p class="whitespace-pre-wrap">Додаткове пояснення: {{ $s->equipment }}</p> @endif
                    <div class="flex flex-wrap gap-3">@if ($v->status === 'draft') {{ ($this->sessionAction)(['id' => $s->id]) }} {{ ($this->removeAction)(['kind' => 'sessions', 'id' => $s->id]) }} @endif</div>
                    @foreach ($entry['content']['stages'] as $stage)
                        <details open class="space-y-3"><summary class="font-semibold cursor-pointer">{{ $stage['title'] }}</summary>
                        @foreach ($stage['blocks'] as $block)
                            @switch($block['type'])
                                @case('action') <p class="whitespace-pre-wrap">{{ implode('', $block['parts']) }}</p> @break
                                @case('hint') <aside class="whitespace-pre-wrap">Підказка: {{ $block['text'] }}</aside> @break
                                @case('image') @if ($block['url']) <img class="max-w-full rounded-lg" src="{{ $block['url'] }}" alt="{{ $block['alt'] }}" loading="lazy"> @else <p>Зображення недоступне за чинним allowlist.</p> @endif @break
                                @case('timer') <p>Таймер «{{ $block['label'] }}»: {{ $block['seconds'] }} секунд. {{ $block['message'] }}</p> @break
                            @endswitch
                        @endforeach
                        </details>
                    @endforeach
                </article>
            @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
