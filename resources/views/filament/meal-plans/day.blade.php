<x-filament-panels::page>
    @php
        $meals = $this->dayMeals();
        $editable = $this->editable();
        $expected = $editable ? app(\App\Services\MealPlans\MealItemWriter::class)->dayToken(auth()->user(), $this->getRecord(), $this->dayId) : '';
        $catalog = $editable ? $this->catalogRows() : collect();
        $allItems = $meals->flatMap->items;
        $cards = app(\App\Support\Presentation\FoodCards::class)->lookup($allItems->pluck('food_id')->merge($catalog->take(12)->pluck('id'))->all());
        $portions = app(\App\Services\MealPlans\MealItemResolver::class)->resolveMany($allItems->pluck('id')->all());
    @endphp
    <div class="plan-editor" data-day-token="{{ $expected }}" x-data="{
        drag: null, busy: false, touch: null, targetMeal: {{ (int) $meals->first()?->id ?? 0 }},
        async drop(meal, before = null) {
            if (!this.drag || this.busy) return;
            const payload = this.drag; this.drag = null; this.busy = true;
            try {
                if (payload.kind === 'food') await $wire.mountAction('addItem', { food: payload.id, meal, expected: this.$root.dataset.dayToken });
                else if (payload.id !== before) await $wire.placeItem(payload.id, meal, before, this.$root.dataset.dayToken);
            } finally { this.busy = false; }
        },
        touchStart(event, kind, id) {
            if (event.pointerType === 'mouse') return;
            event.preventDefault(); this.drag = {kind, id};
            if (event.isTrusted) event.target.setPointerCapture(event.pointerId);
        },
        touchEnd(event) {
            if (event.pointerType === 'mouse') return;
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-drop-meal]');
            if (target) this.drop(Number(target.dataset.dropMeal), target.dataset.before ? Number(target.dataset.before) : null);
            else this.drag = null;
        }
    }" x-init="$store.sidebar?.close()" :aria-busy="busy" @dragend="drag = null" @pointercancel="if ($event.pointerType !== 'mouse') drag = null">
        <p class="plan-muted" id="plan-drag-help">{{ $editable ? 'Перетягніть страву за ручку або скористайтеся меню «Дії позиції». Додавання потребує підтвердження порції; зміни зберігаються відразу.' : 'Опублікований день — лише читання.' }}</p>
        <div class="plan-day-layout">
            <section class="plan-editor-panel plan-meals-panel" aria-labelledby="day-meals-heading" aria-label="Прийоми дня">
                <h2 id="day-meals-heading">Прийоми дня</h2>
                @forelse ($meals as $meal)
                    <section class="plan-meal" data-drop-meal="{{ $meal->id }}" wire:key="meal-{{ $meal->id }}" @dragover.prevent @drop.prevent.stop="drop(Number($event.currentTarget.dataset.dropMeal))" :class="{ 'plan-drop-ready': drag && !busy }">
                        <h3>{{ $meal->title ?: $meal->type->title }}</h3>
                        @forelse ($meal->items as $item)
                            @php $card = $cards[$item->food_id]; $portion = $portions[$item->id] ?? null; @endphp
                            <article class="plan-item" data-item="{{ $item->id }}" data-drop-meal="{{ $meal->id }}" data-before="{{ $item->id }}" wire:key="item-{{ $item->id }}" @dragover.prevent.stop @drop.prevent.stop="drop(Number($event.currentTarget.dataset.dropMeal), Number($event.currentTarget.dataset.before))">
                                @if ($editable)<button type="button" class="plan-drag-handle" draggable="true" @dragstart="drag = { kind: 'item', id: {{ $item->id }} }; $event.dataTransfer.setData('text/plain', 'item:{{ $item->id }}')" @pointerdown="touchStart($event, 'item', {{ $item->id }})" @pointerup="touchEnd($event)" aria-label="Перетягнути {{ $card['label'] }}" aria-describedby="plan-drag-help">⠿</button>@endif
                                <x-food-cover :src="$card['image']" :label="$card['label']" />
                                <div class="plan-item-copy"><strong class="plan-truncate" tabindex="0" title="{{ $card['label'] }}">{{ $card['label'] }}</strong>
                                    <p>{{ \App\Support\Presentation\MealPlanDisplay::quantity($item->quantity) }} {{ $item->uom->label }}</p>
                                    @if ($portion)<small class="plan-muted">{{ \App\Filament\Resources\MealPlans\MealPlanVersionResource::portionLabel($portion->kind) }} · {{ \App\Support\Presentation\MealPlanDisplay::nutrition($portion->nutrition) }}</small>@endif
                                </div>
                                @if ($editable)
                                    <details class="plan-item-menu"><summary aria-label="Дії позиції {{ $card['label'] }}" title="Дії позиції">⋯</summary>
                                        <div class="plan-menu-panel" wire:loading.attr="inert">
                                            <button type="button" @disabled($loop->first) wire:click="placeItem({{ $item->id }}, {{ $meal->id }}, {{ $loop->first ? 'null' : $meal->items[$loop->index - 1]->id }}, '{{ $expected }}')" aria-label="Вище: {{ $card['label'] }}">↑ Вище</button>
                                            <button type="button" @disabled($loop->last) wire:click="placeItem({{ $item->id }}, {{ $meal->id }}, {{ $meal->items[$loop->index + 2]->id ?? 'null' }}, '{{ $expected }}')" aria-label="Нижче: {{ $card['label'] }}">↓ Нижче</button>
                                            <label>Перенести<select class="plan-select" aria-label="Перенести {{ $card['label'] }}" @change="if ($event.target.value) { $wire.placeItem({{ $item->id }}, Number($event.target.value), null, $root.dataset.dayToken); $event.target.value = '' }">
                                                <option value="">Оберіть прийом</option>@foreach ($meals->where('id', '!=', $meal->id) as $target)<option value="{{ $target->id }}">{{ $target->title ?: $target->type->title }}</option>@endforeach
                                            </select></label>
                                            <button type="button" wire:click="mountAction('editItem', { item: {{ $item->id }} })">Змінити</button>
                                            <button type="button" wire:click="mountAction('deleteItem', { item: {{ $item->id }} })">Видалити</button>
                                        </div>
                                    </details>
                                @endif
                            </article>
                        @empty
                            <p class="plan-muted">Порожній прийом. {{ $editable ? 'Додайте страву з каталогу.' : '' }}</p>
                        @endforelse
                        @if ($editable)<p class="plan-drop-hint" aria-hidden="true">Перетягніть сюди, щоб додати в кінець</p>@endif
                    </section>
                @empty
                    <div class="plan-empty">Прийомів ще немає. {{ $editable ? 'Додайте прийом через меню вгорі.' : '' }}</div>
                @endforelse
            </section>
            @if ($editable)
                <aside class="plan-editor-panel plan-catalog" aria-labelledby="catalog-heading">
                    <h2 id="catalog-heading">Каталог страв і продуктів</h2>
                    <label>Пошук каталогу<input class="plan-select" type="search" wire:model.live.debounce.350ms="search" maxlength="150" placeholder="Назва продукту або рецепта"></label>
                    <label>Тип каталогу<select class="plan-select" wire:model.live="catalogType"><option value="">Усі</option><option value="ingredient">Продукти</option><option value="recipe_version">Опубліковані рецепти</option></select></label>
                    @if ($meals->isNotEmpty())
                        <label>Додати до прийому<select class="plan-select" x-model.number="targetMeal" aria-label="Додати до прийому">
                            @foreach ($meals as $meal)<option value="{{ $meal->id }}">{{ $meal->title ?: $meal->type->title }}</option>@endforeach
                        </select></label>
                    @endif
                    <div class="plan-catalog-list" wire:loading.class="opacity-50">
                        @forelse ($catalog->take(12) as $food)
                            <article class="plan-catalog-item" data-food="{{ $food->id }}" wire:key="food-{{ $food->id }}">
                                @php $card = $cards[$food->id]; @endphp
                                <x-food-cover :src="$card['image']" :label="$food->label" variant="catalog" />
                                <div class="plan-catalog-copy"><strong class="plan-truncate" title="{{ $food->label }}" tabindex="0">{{ $food->label }}</strong>
                                    <small class="plan-muted">{{ $food->food_type === 'ingredient' ? 'Продукт' : 'Опублікована версія '.$food->version_number }}</small>
                                </div>
                                <div class="plan-catalog-actions"><button type="button" class="plan-drag-handle" draggable="true" @dragstart="drag = {kind: 'food', id: {{ $food->id }} }; $event.dataTransfer.setData('text/plain', 'food:{{ $food->id }}')" @pointerdown="touchStart($event, 'food', {{ $food->id }})" @pointerup="touchEnd($event)" aria-label="Перетягнути з каталогу {{ $food->label }}" aria-describedby="plan-drag-help">⠿</button>
                                    <button type="button" class="plan-catalog-add" @click="$wire.mountAction('addItem', {food: {{ $food->id }}, meal: Number(targetMeal) || null, expected: $root.dataset.dayToken})" :disabled="busy" aria-label="Додати {{ $food->label }} до обраного прийому">+ Додати</button>
                                </div>
                            </article>
                        @empty
                            <p class="plan-empty">Нічого не знайдено. Змініть пошук або фільтр.</p>
                        @endforelse
                    </div>
                    <nav class="plan-controls" aria-label="Сторінки каталогу"><x-filament::button color="gray" :disabled="$this->catalogPage <= 1" wire:click="catalogNext(-1)">Назад</x-filament::button><span>Сторінка {{ $this->catalogPage }}</span><x-filament::button color="gray" :disabled="$catalog->count() <= 12" wire:click="catalogNext(1)">Далі</x-filament::button></nav>
                </aside>
            @endif
        </div>
    </div>
</x-filament-panels::page>
