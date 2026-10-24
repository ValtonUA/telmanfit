<x-filament-panels::page>
    <div class="batch-editor space-y-6">
        <div class="flex flex-wrap gap-3">
            <x-filament::button wire:click="createDraft" wire:loading.attr="disabled">Нова чернетка A/B/C</x-filament::button>
            <label class="min-w-0 flex-1">Версія рецепта<x-filament::input.wrapper><x-filament::input.select aria-label="Версія рецепта" onchange="if(this.value) window.location.href=this.value"><option value="">Оберіть рецепт</option>@foreach ($versions as $item)<option value="{{ static::getUrl(['version' => $item->id]) }}" @selected($version === $item->id)>#{{ $item->id }} {{ $item->title }} · {{ $item->status === 'draft' ? 'Чернетка' : 'Опубліковано' }} · {{ $item->editor_contract === 'batch_v1' ? 'A/B/C' : 'Старий формат' }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        </div>
        @if ($ownerClientId)<p role="status">Персональний рецепт клієнта #{{ $ownerClientId }}@if ($parentRecipeId) · батьківський рецепт #{{ $parentRecipeId }}@endif. Зміни стосуються цієї копії.</p>@endif
        @if ($message)<p role="status">{{ $message }}</p>@endif
        @if ($errors->any())
            <x-filament::section><div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></x-filament::section>
        @endif
        @if ($boundary)<x-filament::section>{{ $boundary }}</x-filament::section>@endif
        @if ($isLegacy)
            <x-filament::section heading="Явний перехід legacy → A/B/C">
                <div class="space-y-4 min-w-0">
                    <p>{{ $legacyStatus === 'published' ? 'Створюється окрема чернетка того самого рецепта. Опублікована версія, food та історичні плани зберігаються.' : 'Після підтвердження поточна чернетка стане A/B/C. Оригінальні значення зберігаються у snapshots конвертації.' }}</p>
                    <x-filament::button wire:click="previewLegacy" wire:loading.attr="disabled">{{ $legacyStatus === 'published' ? 'Створити нову версію A/B/C' : 'Перетворити чернетку на A/B/C' }}</x-filament::button>
                    @if ($legacyPreview)
                        <p role="status">Це лише preview. A береться зі збережених grams на порцію; servings_count={{ $legacyPreview['original']['root']['servings_count'] }} повторно не множиться. B=7×A; C=B/2, точність 0.01 г. Упаковки не підставляються.</p>
                        <p>Legacy не зберігав історичні nutrition snapshots, назви та factor/UOM labels. Нижче — поточні перевірені джерела; невідомі історичні значення не відновлюються.</p>
                        @foreach ($legacyPreview['changes'] as $key => $change)
                            <div class="space-y-2 break-words">
                                <p>Рядок #{{ $key }}: було {{ $change['quantity'] }}, UOM #{{ $change['uom_id'] }} ({{ $change['uom_code'] }}; поточна назва: {{ $change['uom'] }}), збережено {{ $change['grams_saved'] }} г. Поточна конверсія: 1 {{ $change['uom_code'] }} = {{ $change['factor_now'] }} г → {{ $change['grams_now'] }} г.</p>
                                @if ($change['mismatch'])<p role="alert">Розбіжність із поточним довідником. A залишає збережені {{ $change['grams_saved'] }} г. Потрібне явне прийняття.</p>@endif
                                <p>Поточне джерело: {{ $legacyPreview['prepared']['snapshots'][$key]['name'] }}. КБЖВ на 100 г: {{ $legacyPreview['prepared']['snapshots'][$key]['kcal'] }} / {{ $legacyPreview['prepared']['snapshots'][$key]['protein'] }} / {{ $legacyPreview['prepared']['snapshots'][$key]['fat'] }} / {{ $legacyPreview['prepared']['snapshots'][$key]['carbs'] }}.</p>
                                @foreach (['single' => 'A', 'week' => 'B', 'half_week' => 'C'] as $code => $label)
                                    <p>{{ $label }}: {{ $legacyPreview['prepared']['evaluation']['rows'][$key][$code]['grams'] }} г</p>
                                @endforeach
                            </div>
                        @endforeach
                        <div class="space-y-2">
                            @foreach ($legacyPreview['original']['recipe_components'] as $component)<p>Компонент: {{ $component['title'] }} · порядок {{ $component['sort_order'] }}</p>@endforeach
                            @foreach ($legacyPreview['original']['recipe_directions'] as $step)
                                @if ($step['image_url'])<p class="break-all">Зображення кроку {{ $step['direction_number'] }}: {{ $step['image_url'] }}</p>@endif
                            @endforeach
                            @foreach ($legacyPreview['nutrition'] as $name => $values)<p>{{ $name }}: збережено {{ $values['legacy'] ?? 'невідомо' }} → нова A {{ $values['current_A'] }}</p>@endforeach
                            @foreach (['single' => 'A', 'week' => 'B', 'half_week' => 'C'] as $code => $label)
                                <h3>{{ $label }} · кроки та обладнання</h3>
                                @foreach ($legacyPreview['rendered'][$code] as $text)<p class="break-words">{{ $text }}</p>@endforeach
                                @foreach ($legacyPreview['prepared']['equipment'] as $tool)<p>{{ $tool[$code]['name'] }}: {{ $tool[$code]['min_value'] ?? 'Без числової вимоги' }} {{ $tool[$code]['uom'] }} · {{ $tool[$code]['tip'] }}. Після переходу підтвердьте достатність у штатному review.</p>@endforeach
                            @endforeach
                        </div>
                        <label class="flex gap-2"><x-filament::input.checkbox wire:model="legacySourcesAccepted" />Приймаю поточні джерела КБЖВ, UOM та всі показані розбіжності (явний Refresh).</label>
                        <label class="flex gap-2"><x-filament::input.checkbox wire:model="legacyReviewed" />Переглянув A/B/C, спільні кроки, зображення й обладнання. Після переходу перевірю manual/adequacy перед Publish.</label>
                        <div class="flex flex-wrap gap-3">
                            <x-filament::button wire:click="convertLegacy" wire:loading.attr="disabled" wire:confirm="Явно підтверджуєте показану конвертацію? Для published буде створена окрема чернетка; старі плани й призначення зберігаються.">Підтвердити перехід на A/B/C</x-filament::button>
                            <x-filament::button color="gray" wire:click="cancelLegacy">Скасувати конвертацію</x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif
        @if ($canCreateVersion)
            <x-filament::button wire:click="createVersion" wire:loading.attr="disabled" wire:confirm="Створити нову чернетку A/B/C? Ручні значення збережуться й потребуватимуть повторного підтвердження. Зміни довідників приймаються окремим Refresh.">Створити нову версію A/B/C</x-filament::button>
        @endif
        @if ($canCreateVersion)
            <x-filament::section heading="Персональна копія рецепта">
                <div class="space-y-3 min-w-0">
                    <label>Клієнт для копії<x-filament::input.wrapper><x-filament::input.select aria-label="Клієнт для копії" wire:model="copyOwnerId"><option value="">Оберіть клієнта</option>@foreach ($copyClients as $client)<option value="{{ $client->id }}">#{{ $client->id }} {{ $client->first_name }} {{ $client->last_name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <p>Окремий рецепт і чернетка A/B/C для обраного клієнта. Оригінал збережеться; план і призначення автоматично не змінюються. Ручні значення потребують повторного review, зміни довідників — явного Refresh.</p>
                    <x-filament::button wire:click="createPersonalCopy" wire:loading.attr="disabled" wire:confirm="Створити окремий персональний рецепт для обраного клієнта без призначення до плану?">Створити персональну копію A/B/C</x-filament::button>
                </div>
            </x-filament::section>
        @endif
        @if ($version && !$boundary)
            @if ($ingredients->isEmpty() || $units->isEmpty() || $categories->isEmpty() || $tools->isEmpty())
                <p role="status">Довідники ще не заповнені повністю. Для вибору потрібні погоджені інгредієнти, конверсії, одиниці, категорії та обладнання. Зверніться до адміністратора довідників; довільні значення не створюються.</p>
            @endif
            @if (empty($payload['rows']))<p>Додайте перший інгредієнт. Незавершену чернетку можна зберегти.</p>@endif
            <p>Версія #{{ $version }} · ревізія <span data-testid="revision">{{ $revision }}</span>. Перегляд не змінює БД.</p>
            <fieldset wire:loading.attr="disabled" class="min-w-0 space-y-6">
                <x-filament::section heading="Спільний рецепт">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>Назва<x-filament::input.wrapper><x-filament::input aria-label="Назва" wire:model.live.debounce.500ms="payload.title" /></x-filament::input.wrapper></label>
                        <label>Категорія<x-filament::input.wrapper><x-filament::input.select aria-label="Категорія" wire:model.live="payload.category_id"><option value="">Оберіть</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    </div>
                </x-filament::section>
                @foreach ($payload['rows'] as $index => $row)
                    <x-filament::section wire:key="row-{{ $row['id'] }}">
                        <div class="space-y-4">
                            <p class="break-all">Рядок {{ $row['id'] }} · токен: {{ $this->ingredientToken($row['id']) }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label>Інгредієнт<x-filament::input.wrapper><x-filament::input.select aria-label="Інгредієнт {{ $index + 1 }}" wire:model.live="payload.rows.{{ $index }}.ingredient_id"><option value="">Оберіть</option>@foreach ($ingredients as $i)<option value="{{ $i->id }}">{{ $i->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                <label>Компонент<x-filament::input.wrapper><x-filament::input.select wire:model.live="payload.rows.{{ $index }}.component_id"><option value="">Без компонента</option>@foreach ($payload['components'] as $c)<option value="{{ $c['id'] }}">{{ $c['title'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                            </div>
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="space-y-2"><h3>A · 1 порція · g</h3><x-filament::input.wrapper><x-filament::input aria-label="A грами {{ $index + 1 }}" inputmode="decimal" wire:model.live.debounce.500ms="payload.rows.{{ $index }}.quantity" /></x-filament::input.wrapper></div>
                                @foreach (['week' => 'B · 7 порцій', 'half_week' => 'C · фізична партія на 3 або 4 дні'] as $code => $label)
                                    @php($q = $preview['evaluation']['rows'][$row['id']][$code] ?? null)
                                    <div class="space-y-2">
                                        <h3>{{ $label }} <x-filament::badge>{{ $row[$code]['mode'] === 'auto' ? 'Автоматично' : 'Вручну' }}</x-filament::badge></h3>
                                        <x-filament::input.wrapper><x-filament::input.select aria-label="Режим {{ $code }} {{ $index + 1 }}" wire:model.live="payload.rows.{{ $index }}.{{ $code }}.mode"><option value="auto">Автоматично</option><option value="manual">Вручну</option></x-filament::input.select></x-filament::input.wrapper>
                                        @if ($row[$code]['mode'] === 'manual')
                                            <x-filament::input.wrapper><x-filament::input aria-label="Кількість {{ $code }} {{ $index + 1 }}" inputmode="decimal" wire:model.live.debounce.500ms="payload.rows.{{ $index }}.{{ $code }}.quantity" /></x-filament::input.wrapper>
                                            <x-filament::input.wrapper><x-filament::input.select aria-label="UOM {{ $code }} {{ $index + 1 }}" wire:model.live="payload.rows.{{ $index }}.{{ $code }}.uom_id"><option value="">Оберіть UOM</option>@foreach ($units as $u)@if ($u->code === 'g' || collect($conversions[$row['ingredient_id']] ?? [])->contains('uom_id', $u->id))<option value="{{ $u->id }}">{{ $u->label }} ({{ $u->code }})</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper>
                                            <x-filament::button size="sm" color="gray" wire:click="resetQuantity({{ $index }}, '{{ $code }}')">Повернути кількість до авто</x-filament::button>
                                        @endif
                                        @if ($q)
                                            <p data-testid="{{ $code }}-{{ $index }}">{{ $q['quantity'] }} {{ $q['uom'] }} · {{ $q['grams'] }} їстівних g · 1 {{ $q['uom'] }} = {{ $q['factor'] }} г</p>
                                            @if ($q['review'])<x-filament::badge color="warning">Ручне значення потребує підтвердження: змінилися залежності або кількість</x-filament::badge>@endif
                                        @endif
                                        @if ($row[$code]['mode'] === 'auto' && $q)
                                            <x-filament::button size="sm" color="gray" wire:click="manualQuantity({{ $index }}, '{{ $code }}')">Змінити вручну</x-filament::button>
                                        @endif
                                        @if ($code === 'week' && $row[$code]['mode'] === 'auto')
                                            <label>Одиниця B<x-filament::input.wrapper><x-filament::input.select aria-label="Авто UOM B {{ $index + 1 }}" wire:model="payload.rows.{{ $index }}.week.uom_id"><option value="">Грами</option>@foreach ($units as $u)@if ($u->code === 'g' || collect($conversions[$row['ingredient_id']] ?? [])->contains('uom_id', $u->id))<option value="{{ $u->id }}">{{ $u->label }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                            <label>Пресет B<x-filament::input.wrapper><x-filament::input.select aria-label="Пресет B {{ $index + 1 }}" wire:model="presetChoices.{{ $index }}"><option value="exact">Точні грами · крупи</option><option value="nearest100">До 100 г · м’ясо/риба, за вибором</option><option value="1.00">Вгору до цілої штуки / упаковки</option><option value="0.50">Вгору до половини упаковки</option><option value="0.25">Вгору до чверті упаковки</option></x-filament::input.select></x-filament::input.wrapper></label>
                                            <p>Дробові упаковки обирайте лише за підтвердженим правилом. Дозволена конверсія обов’язкова; фасування система не визначає.</p>
                                            <x-filament::button size="sm" color="gray" wire:click="applyPreset({{ $index }})" wire:confirm="Підтверджуєте вибрану конверсію й допустимий крок упаковки? Результат буде показано до Save.">Застосувати й підтвердити пресет B</x-filament::button>
                                        @endif
                                        @if ($code === 'half_week')
                                            <label>Підтверджений крок для UOM B<x-filament::input.wrapper><x-filament::input aria-label="Крок C {{ $index + 1 }}" inputmode="decimal" wire:model="payload.rows.{{ $index }}.half_week.step" /></x-filament::input.wrapper></label>
                                            <x-filament::button size="sm" color="gray" wire:click="acceptPolicy({{ $index }})">Підтвердити правило округлення C</x-filament::button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <x-filament::button color="danger" size="sm" wire:click="removeRow({{ $index }})" wire:confirm="{{ $this->deletionImpact('rows', $index) }}">Видалити інгредієнт</x-filament::button>
                        </div>
                    </x-filament::section>
                @endforeach
                <x-filament::button color="gray" wire:click="addRow">Додати інгредієнт</x-filament::button>
                <x-filament::section heading="Компоненти та кроки">
                    <div class="space-y-4">
                        @foreach ($payload['components'] as $n => $c)
                            <div class="flex gap-2" wire:key="component-{{ $c['id'] }}"><x-filament::input.wrapper class="min-w-0 flex-1"><x-filament::input aria-label="Компонент {{ $n + 1 }}" wire:model.live.debounce.500ms="payload.components.{{ $n }}.title" /></x-filament::input.wrapper><x-filament::button color="danger" size="sm" wire:click="removeDetail('components', {{ $n }})" wire:confirm="{{ $this->deletionImpact('components', $n) }}">×</x-filament::button></div>
                        @endforeach
                        <x-filament::button color="gray" wire:click="addComponent">Додати компонент</x-filament::button>
                        @foreach ($payload['directions'] as $n => $text)
                            <div class="space-y-2"><label>Крок {{ $n + 1 }}<x-filament::input.wrapper><textarea class="batch-textarea" aria-label="Крок {{ $n + 1 }}" wire:model.live.debounce.500ms="payload.directions.{{ $n }}"></textarea></x-filament::input.wrapper></label><x-filament::button color="danger" size="sm" wire:click="removeDetail('directions', {{ $n }})">Видалити крок</x-filament::button></div>
                        @endforeach
                        <x-filament::button color="gray" wire:click="addDirection">Додати крок</x-filament::button>
                        <p>Токени quantity/grams посилаються на ID рядка. Час і температура задаються автором і не масштабуються.</p>
                    </div>
                </x-filament::section>
                <x-filament::section heading="Обладнання · стале / пропорційне">
                    <div class="space-y-4">
                        @foreach ($payload['equipment'] as $n => $tool)
                            <div class="space-y-3" wire:key="tool-{{ $tool['id'] }}">
                                <label>Обладнання<x-filament::input.wrapper><x-filament::input.select wire:model.live="payload.equipment.{{ $n }}.kitchen_equipment_id"><option value="">Оберіть</option>@foreach ($tools as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                <label>Значення A<x-filament::input.wrapper><x-filament::input aria-label="Обладнання A кількість {{ $n + 1 }}" wire:model.live.debounce.500ms="payload.equipment.{{ $n }}.min_value" /></x-filament::input.wrapper></label>
                                <label>UOM<x-filament::input.wrapper><x-filament::input.select wire:model.live="payload.equipment.{{ $n }}.uom_id"><option value="">Без UOM</option>@foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                <label>Масштабування<x-filament::input.wrapper><x-filament::input.select wire:model.live="payload.equipment.{{ $n }}.scaling_mode"><option value="fixed">Стале</option><option value="proportional">Пропорційне ×1/7/3.5</option></x-filament::input.select></x-filament::input.wrapper></label>
                                <label>Порада<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.500ms="payload.equipment.{{ $n }}.tip" /></x-filament::input.wrapper></label>
                                <p class="break-all">Токен: {{ $this->equipmentToken($tool['id']) }} · характеристики: name, min_value, uom, tip</p>
                                <div class="grid gap-4 lg:grid-cols-3">
                                @foreach (['single' => 'A', 'week' => 'B', 'half_week' => 'C'] as $code => $label)
                                    @php($eq = $preview['equipment'][$tool['id']][$code] ?? null)
                                    <div class="space-y-2"><h3>Обладнання {{ $label }}</h3>
                                    @if ($eq)
                                        <p data-testid="equipment-{{ $code }}-{{ $n }}">{{ $eq['min_value'] ?? 'Без числової вимоги' }} {{ $eq['uom'] }} · {{ $eq['mode'] === 'manual' ? 'Вручну' : 'Автоматично' }}</p>
                                        <p>Автоматичний результат: {{ $eq['automatic'] ?? 'Без числової вимоги' }} {{ $eq['automatic_uom'] }}</p>
                                        @if ($eq['review'])<p role="status">{{ $eq['reason'] }}</p>@endif
                                    @endif
                                    @if ($code !== 'single')
                                        @if (($tool[$code]['mode'] ?? 'auto') === 'manual')
                                            <label>Ручна кількість {{ $label }}<x-filament::input.wrapper><x-filament::input aria-label="Обладнання {{ $label }} кількість {{ $n + 1 }}" inputmode="decimal" wire:model.live.debounce.500ms="payload.equipment.{{ $n }}.{{ $code }}.min_value" /></x-filament::input.wrapper></label>
                                            <label>Ручна UOM {{ $label }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="payload.equipment.{{ $n }}.{{ $code }}.uom_id"><option value="">Без UOM</option>@foreach ($units as $u)<option value="{{ $u->id }}">{{ $u->label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                            <label>Ручна порада {{ $label }}<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.500ms="payload.equipment.{{ $n }}.{{ $code }}.tip" /></x-filament::input.wrapper></label>
                                            <x-filament::button color="gray" size="sm" wire:click="resetEquipment({{ $n }}, '{{ $code }}')">Повернути обладнання {{ $label }} до авто</x-filament::button>
                                        @elseif ($eq)
                                            <x-filament::button color="gray" size="sm" wire:click="manualEquipment({{ $n }}, '{{ $code }}')">Обладнання {{ $label }} вручну</x-filament::button>
                                        @endif
                                    @endif
                                    </div>
                                @endforeach
                                </div>
                                <x-filament::button color="danger" size="sm" wire:click="removeDetail('equipment', {{ $n }})" wire:confirm="{{ $this->deletionImpact('equipment', $n) }}">Видалити обладнання</x-filament::button>
                            </div>
                        @endforeach
                        <x-filament::button color="gray" wire:click="addEquipment">Додати обладнання</x-filament::button>
                        <p>Перевірте достатність кількості й місткості A/B/C. Підтвердження нижче діє лише для поточного складу та параметрів; порожнє значення означає відсутність числової вимоги.</p>
                    </div>
                </x-filament::section>
                @if ($preview)
                    <x-filament::section heading="Preview КБЖВ та інструкцій">
                        @if ($preview['evaluation']['incomplete'])<p role="status">Неповний розрахунок: незавершені рядки не включені в КБЖВ.</p>@endif
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($preview['evaluation']['portions'] as $label => $totals)<div><h3>{{ $label }}</h3>@foreach ($totals as $name => $value)<p>{{ $name }}: {{ $value }}</p>@endforeach</div>@endforeach
                        </div>
                        <div class="mt-4"><h3>Тижнева різниця B проти 2×C</h3>@foreach ($preview['evaluation']['totals']['week'] as $name => $value)<p>{{ $name }}: {{ \Brick\Math\BigDecimal::of($value)->minus(\Brick\Math\BigDecimal::of($preview['evaluation']['totals']['half_week'][$name])->multipliedBy(2)) }}</p>@endforeach</div>
                        <div class="mt-4 grid gap-4 lg:grid-cols-3">@foreach (['single' => 'A', 'week' => 'B', 'half_week' => 'C'] as $code => $label)<div><h3>Кроки {{ $label }}</h3>@foreach ($this->renderedDirections($code) as $text)<p class="whitespace-pre-wrap break-words">{{ $text }}</p>@endforeach</div>@endforeach</div>
                    </x-filament::section>
                    @if ($preview['diff'] || $preview['equipment_diff'])
                        <x-filament::section heading="Довідник змінився · потрібна явна згода">
                            @foreach ($preview['equipment_diff'] as $toolId => $changes)@foreach ($changes as $code => $description)<p>Обладнання {{ $toolId }} · {{ $code }}: змінився довідник ({{ $description }}). Потрібні Refresh та повторна перевірка достатності.</p>@endforeach @endforeach
                            @foreach ($preview['diff'] as $rowId => $changes)@foreach ($changes as $code => $change)<p class="break-words">Рядок {{ $rowId }} {{ $code }}: {{ $change['before']['grams'] }} g → {{ $change['after']['grams'] }} g; quantity {{ $change['after']['quantity'] }} {{ $change['after']['uom'] }}. Нутрієнти та конверсії оновляться після згоди.</p><p>Нутрієнти на 100 g: @foreach (['kcal', 'protein', 'fat', 'carbs'] as $nutrient) {{ $nutrient }} {{ $change['ingredient_before'][$nutrient] }} → {{ $change['ingredient_after'][$nutrient] }}; @endforeach factor {{ $change['before']['snapshot']['factor'] }} → {{ $change['after']['factor'] }}</p>@endforeach @endforeach
                            <x-filament::button color="warning" wire:click="saveDraft(true)">Прийняти diff і Refresh</x-filament::button>
                        </x-filament::section>
                    @endif
                @endif
                <p>Дія «Підтвердити ручні значення й обладнання» також підтверджує перевірену автором достатність усього обладнання A/B/C.</p>
                <div class="flex flex-wrap gap-3">
                    <x-filament::button color="gray" wire:click="calculate">Оновити preview</x-filament::button>
                    <x-filament::button wire:click="saveDraft">Зберегти чернетку</x-filament::button>
                    <x-filament::button color="warning" wire:click="saveDraft(false, true)">Підтвердити ручні значення й обладнання</x-filament::button>
                    <x-filament::button color="gray" wire:click="reload" wire:confirm="Незбережені зміни буде втрачено. Перезавантажити?">Перезавантажити</x-filament::button>
                </div>
                <label class="flex items-start gap-3"><input type="checkbox" wire:model="publicationConfirmed" /><span>Склад завершений; перевірено manual, кроки A/B/C та обладнання. Публікувати збережену версію.</span></label>
                <x-filament::button color="success" wire:click="publish" wire:confirm="Опублікувати A/B/C атомарно? Версія стане незмінною.">Опублікувати A/B/C</x-filament::button>
            </fieldset>
        @endif
    </div>
</x-filament-panels::page>
