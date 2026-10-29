<x-filament-panels::page>
    @php
        $days = $this->selectedWeek();
        $count = $this->weekCount();
        $weekNumber = $days->isEmpty() ? 1 : intdiv($days->first()->day_number - 1, 7) + 1;
        $editable = $this->editable();
        $weekItems = $days->flatMap(fn ($d) => $d->meals->flatMap->items);
        $cards = app(\App\Support\Presentation\FoodCards::class)->lookup($weekItems->pluck('food_id')->all());
        $formats = \Illuminate\Support\Facades\DB::table('recipe_batch_formats')->whereIn('id', $weekItems->pluck('recipe_batch_format_id')->filter())->pluck('code', 'id');
    @endphp
    <div class="plan-editor" x-data="{}" x-init="Alpine.store('telmanfitMealPlanWeek', { selectedDay: {{ $days->first()?->id ?? 0 }} }); $store.sidebar?.close()">
        <div class="plan-toolbar">
            <div><strong>{{ $this->getRecord()->plan->title }}</strong><p class="plan-muted"><span>{{ \App\Filament\Resources\MealPlans\MealPlanVersionResource::statusLabel($this->getRecord()->status) }}</span> · точна версія #{{ $this->getRecord()->id }}</p></div>
            @if ($count)
                <nav aria-label="Тижні раціону" class="plan-controls">
                    <x-filament::button color="gray" wire:click="selectWeek({{ $weekNumber - 1 }})" :disabled="$weekNumber <= 1">Попередній тиждень</x-filament::button>
                    <span>Тиждень {{ $weekNumber }} / {{ $count }}</span>
                    <x-filament::button color="gray" wire:click="selectWeek({{ $weekNumber + 1 }})" :disabled="$weekNumber >= $count">Наступний тиждень</x-filament::button>
                </nav>
            @endif
        </div>
        @if ($days->isEmpty())
            <div class="plan-empty"><h2>Порожня чернетка: днів ще немає.</h2><p>Чернетка ще не готова до публікації. Відкриття сторінки не створює даних.</p>@if ($editable)<x-filament::button wire:click="mountAction('createFirstWeek')">Створити 7 днів</x-filament::button>@endif</div>
        @else
            <p class="plan-muted">Дні — порядкові номери у шаблоні. {{ $editable ? 'Виберіть день, щоб налаштувати меню. Дія «Опублікувати» перевірить готовність усього раціону.' : 'Опублікована версія — лише читання.' }}</p>
            <label class="plan-mobile-picker">Показати день
                <select x-model.number="$store.telmanfitMealPlanWeek.selectedDay" class="plan-select" wire:key="day-picker-{{ $weekNumber }}">
                    @foreach ($days as $day)<option value="{{ $day->id }}">{{ $this->dayLabel($day->day_number) }}</option>@endforeach
                </select>
            </label>
            <div class="plan-week" wire:key="week-{{ $weekNumber }}" x-init="$store.telmanfitMealPlanWeek.selectedDay = {{ $days->first()->id }}">
                @foreach ($days as $day)
                    @php $items = $day->meals->sum(fn ($m) => $m->items->count()); @endphp
                    <article class="plan-day" data-day="{{ $day->id }}" :class="{ 'plan-selected-day': $store.telmanfitMealPlanWeek.selectedDay === {{ $day->id }} }" wire:key="day-{{ $day->id }}">
                        <h2>{{ $this->dayLabel($day->day_number) }}</h2>
                        <div class="plan-day-body">
                            @forelse ($day->meals->filter(fn ($m) => $m->items->isNotEmpty()) as $meal)
                                <section><h3>{{ $meal->title ?: $meal->type->title }}</h3>
                                    <ul>@foreach ($meal->items as $item)
                                        @php $card = $cards[$item->food_id]; @endphp
                                        <li class="plan-week-tile" x-data="{ broken: false }" x-init="$nextTick(() => { const img = $el.querySelector('img'); if (img?.complete && !img.naturalWidth) broken = true })" :class="{ 'plan-photo-tile': {{ $card['image'] ? 'true' : 'false' }} && !broken }">
                                            @if ($card['image'])
                                                <img src="{{ $card['image'] }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" sizes="(min-width:1280px) 14vw, 90vw" width="320" height="240" x-show="!broken" x-on:error="broken = true">
                                            @endif
                                            <div class="plan-week-caption"><span class="plan-truncate" tabindex="0" title="{{ $card['label'] }}">{{ $card['label'] }}</span>
                                                <small><span>{{ \App\Support\Presentation\MealPlanDisplay::quantity($item->quantity) }}</span> {{ $item->uom->label }}@if($item->recipe_portion_divisor) · {{ ['single' => 'A', 'week' => 'B', 'half_week' => 'C'][$formats[$item->recipe_batch_format_id]] }}/{{ $item->recipe_portion_divisor }} · @endif</small>
                                            </div>
                                        </li>
                                    @endforeach</ul>
                                </section>
                            @empty
                                <p class="plan-muted">Меню ще не заповнене.</p>
                            @endforelse
                        </div>
                        @if ($editable)
                            <x-filament::button tag="a" :href="\App\Filament\Resources\MealPlans\MealPlanVersionResource::getUrl('day', ['record' => $this->getRecord(), 'day' => $day->id])" class="plan-day-cta">{{ $items ? 'Редагувати день' : 'Заповнити день' }}</x-filament::button>
                        @else
                            <a wire:navigate class="plan-read-link" href="{{ \App\Filament\Resources\MealPlans\MealPlanVersionResource::getUrl('day', ['record' => $this->getRecord(), 'day' => $day->id]) }}">Переглянути день</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
