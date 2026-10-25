<x-filament-panels::page>
    @php
        $meals = $this->dayMeals();
        $editable = $this->editable();
        $expected = $editable ? app(\App\Services\MealPlans\MealItemWriter::class)->dayToken(auth()->user(), $this->getRecord(), $this->dayId) : '';
        $catalog = $editable ? $this->catalogRows() : collect();
    @endphp
    <div class="plan-editor" data-day-token="{{ $expected }}" x-data="{
        drag: null, busy: false, touch: null,
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
        <p class="plan-muted" id="plan-drag-help">{{ $editable ? 'Перетягніть страву за ручку або скористайтеся кнопками й меню «Позиції». Додавання потребує підтвердження порції; зміни зберігаються відразу.' : 'Опублікований день — лише читання.' }}</p>
        <div class="plan-day-layout">
            <section aria-labelledby="day-meals-heading">
                <h2 id="day-meals-heading">Прийоми дня</h2>
                @forelse ($meals as $meal)
                    <section class="plan-meal" data-drop-meal="{{ $meal->id }}" wire:key="meal-{{ $meal->id }}" @dragover.prevent @drop.prevent.stop="drop(Number($event.currentTarget.dataset.dropMeal))" :class="{ 'plan-drop-ready': drag && !busy }">
                        <h3>{{ $meal->title ?: $meal->type->title }}</h3>
                        @forelse ($meal->items as $item)
                            <article class="plan-item" data-item="{{ $item->id }}" data-drop-meal="{{ $meal->id }}" data-before="{{ $item->id }}" wire:key="item-{{ $item->id }}" @dragover.prevent.stop @drop.prevent.stop="drop(Number($event.currentTarget.dataset.dropMeal), Number($event.currentTarget.dataset.before))">
                                <div class="plan-item-title">
                                    @if ($editable)<button type="button" class="plan-drag-handle" draggable="true" @dragstart="drag = { kind: 'item', id: {{ $item->id }} }; $event.dataTransfer.setData('text/plain', 'item:{{ $item->id }}')" @pointerdown="touchStart($event, 'item', {{ $item->id }})" @pointerup="touchEnd($event)" aria-label="Перетягнути {{ $item->food->label }}" aria-describedby="plan-drag-help">⠿</button>@endif
                                    <strong>{{ $item->food->label }}</strong>
                                </div>
                                <p>{{ $item->quantity }} {{ $item->uom->label }}</p>
                                @php
                                    try { $portion = app(\App\Services\MealPlans\MealItemResolver::class)->resolve($item); } catch (\DomainException) { $portion = null; }
                                @endphp
                                @if ($portion)<small class="plan-muted">{{ \App\Filament\Resources\MealPlans\MealPlanVersionResource::portionLabel($portion->kind) }} · {{ $portion->nutrition['kcal'] }} ккал · Б {{ $portion->nutrition['protein'] }} · Ж {{ $portion->nutrition['fat'] }} · В {{ $portion->nutrition['carbs'] }}</small>@endif
                                @if ($editable)
                                    <div class="plan-controls" wire:loading.attr="inert">
                                        <x-filament::button color="gray" size="xs" :disabled="$loop->first" wire:click="placeItem({{ $item->id }}, {{ $meal->id }}, {{ $loop->first ? 'null' : $meal->items[$loop->index - 1]->id }}, '{{ $expected }}')" aria-label="Вище: {{ $item->food->label }}">↑ Вище</x-filament::button>
                                        <x-filament::button color="gray" size="xs" :disabled="$loop->last" wire:click="placeItem({{ $item->id }}, {{ $meal->id }}, {{ $meal->items[$loop->index + 2]->id ?? 'null' }}, '{{ $expected }}')" aria-label="Нижче: {{ $item->food->label }}">↓ Нижче</x-filament::button>
                                        <label class="plan-move">Перенести
                                            <select class="plan-select" aria-label="Перенести {{ $item->food->label }}" @change="if ($event.target.value) { $wire.placeItem({{ $item->id }}, Number($event.target.value), null, $root.dataset.dayToken); $event.target.value = '' }">
                                                <option value="">Оберіть прийом</option>
                                                @foreach ($meals->where('id', '!=', $meal->id) as $target)<option value="{{ $target->id }}">{{ $target->title ?: $target->type->title }}</option>@endforeach
                                            </select>
                                        </label>
                                    </div>
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
                <aside class="plan-catalog" aria-labelledby="catalog-heading">
                    <h2 id="catalog-heading">Каталог страв і продуктів</h2>
                    <label>Пошук каталогу<input class="plan-select" type="search" wire:model.live.debounce.350ms="search" maxlength="150" placeholder="Назва продукту або рецепта"></label>
                    <label>Тип каталогу<select class="plan-select" wire:model.live="catalogType"><option value="">Усі</option><option value="ingredient">Продукти</option><option value="recipe_version">Опубліковані рецепти</option></select></label>
                    <div class="plan-catalog-list" wire:loading.class="opacity-50">
                        @forelse ($catalog->take(12) as $food)
                            <article class="plan-catalog-item" data-food="{{ $food->id }}" wire:key="food-{{ $food->id }}">
                                <div class="plan-item-title"><button type="button" class="plan-drag-handle" draggable="true" @dragstart="drag = {kind: 'food', id: {{ $food->id }} }; $event.dataTransfer.setData('text/plain', 'food:{{ $food->id }}')" @pointerdown="touchStart($event, 'food', {{ $food->id }})" @pointerup="touchEnd($event)" aria-label="Перетягнути з каталогу {{ $food->label }}" aria-describedby="plan-drag-help">⠿</button><strong>{{ $food->label }}</strong></div>
                                <small class="plan-muted">{{ $food->food_type === 'ingredient' ? 'Продукт · грами або чинні одиниці' : 'Точна опублікована версія '.$food->version_number.' · '.($food->editor_contract === 'batch_v1' ? 'явні A/1 · B/7 · C/3 · C/4' : 'legacy порція') }}</small>
                                <label>Додати до прийому<select class="plan-select" aria-label="Додати {{ $food->label }}" @change="if ($event.target.value) { drag = {kind: 'food', id: {{ $food->id }} }; drop(Number($event.target.value)); $event.target.value = '' }" :disabled="busy">
                                    <option value="">Оберіть прийом…</option>
                                    @foreach ($meals as $meal)<option value="{{ $meal->id }}">{{ $meal->title ?: $meal->type->title }}</option>@endforeach
                                </select></label>
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
