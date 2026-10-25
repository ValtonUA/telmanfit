<x-filament-panels::page>
    @php
        $days = $this->selectedWeek();
        $count = $this->weekCount();
        $weekNumber = $days->isEmpty() ? 1 : intdiv($days->first()->day_number - 1, 7) + 1;
        $editable = $this->editable();
    @endphp
    <div class="plan-editor" x-data="{ selectedDay: {{ $days->first()?->id ?? 0 }} }" x-init="$store.sidebar?.close()">
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
            <div class="plan-empty"><h2>Порожня чернетка: днів ще немає.</h2><p>Чернетка ще не готова до публікації. Додайте семиденний тиждень через меню вгорі. Відкриття сторінки не створює даних.</p></div>
        @else
            <p class="plan-muted">Дні — порядкові номери у шаблоні. {{ $editable ? 'Чернетка ще не готова до публікації. Виберіть день, щоб налаштувати його меню.' : 'Опублікована версія — лише читання.' }}</p>
            <label class="plan-mobile-picker">Показати день
                <select x-model.number="selectedDay" class="plan-select" wire:key="day-picker-{{ $weekNumber }}">
                    @foreach ($days as $day)<option value="{{ $day->id }}">День {{ $day->day_number }}</option>@endforeach
                </select>
            </label>
            <div class="plan-week" wire:key="week-{{ $weekNumber }}" x-init="selectedDay = {{ $days->first()->id }}">
                @foreach ($days as $day)
                    @php $items = $day->meals->sum(fn ($m) => $m->items->count()); @endphp
                    <article class="plan-day" data-day="{{ $day->id }}" :class="{ 'plan-selected-day': selectedDay === {{ $day->id }} }" wire:key="day-{{ $day->id }}">
                        <h2>День {{ $day->day_number }}</h2>
                        <div class="plan-day-body">
                            @forelse ($day->meals->filter(fn ($m) => $m->items->isNotEmpty()) as $meal)
                                <section><h3>{{ $meal->title ?: $meal->type->title }}</h3>
                                    <ul>@foreach ($meal->items as $item)
                                        <li><span class="plan-truncate" title="{{ $item->food->label }}">{{ $item->food->label }}</span><small class="plan-muted"><span>{{ $item->quantity }}</span> {{ $item->uom->label }}@if($item->recipe_portion_divisor) · {{ ['single' => 'A', 'week' => 'B', 'half_week' => 'C'][\Illuminate\Support\Facades\DB::table('recipe_batch_formats')->where('id', $item->recipe_batch_format_id)->value('code')] ?? '' }}/{{ $item->recipe_portion_divisor }} · @endif</small></li>
                                    @endforeach</ul>
                                </section>
                            @empty
                                <p class="plan-muted">Меню ще не заповнене.</p>
                            @endforelse
                        </div>
                        @if ($editable)
                            <x-filament::button tag="a" :href="\App\Filament\Resources\MealPlans\MealPlanVersionResource::getUrl('day', ['record' => $this->getRecord(), 'day' => $day->id])" class="plan-day-cta">{{ $items ? 'Редагувати день' : 'Заповнити день' }}</x-filament::button>
                        @else
                            <a class="plan-read-link" href="{{ \App\Filament\Resources\MealPlans\MealPlanVersionResource::getUrl('day', ['record' => $this->getRecord(), 'day' => $day->id]) }}">Переглянути день</a>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
