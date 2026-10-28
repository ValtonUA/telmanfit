<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MealPlans\ClientMenuFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';

$mode = $argv[1] ?? 'setup';
$email = 't52-browser@example.invalid';
if ($mode !== 'setup') {
    $user = DB::table('users')->where('email_normalized', $email)->value('id');
    $client = DB::table('clients')->where('user_id', $user)->value('id');
    if ($mode === 'cooking') {
        DB::transaction(function () use ($client): void {
            if (! Schema::hasTable('cooking_session_equipment')) {
                (require database_path('migrations/2026_10_28_020000_add_cooking_session_equipment.php'))->up();
            }
            $old = DB::table('client_meal_plan_assignments')->where('client_id', $client)->orderByDesc('id')->first();
            if (! $old) {
                throw new RuntimeException('Synthetic active assignment missing.');
            }
            $slot = DB::table('meal_items as i')->join('meals as m', 'm.id', '=', 'i.meal_id')->join('meal_plan_days as d', 'd.id', '=', 'm.meal_plan_day_id')
                ->join('recipe_versions as r', 'r.id', '=', 'i.recipe_version_id')->where('d.meal_plan_version_id', $old->meal_plan_version_id)
                ->whereNotNull('i.recipe_version_id')->orderBy('d.day_number')->orderBy('m.id')->orderBy('i.id')
                ->first(['i.recipe_version_id', 'i.recipe_batch_format_id', 'r.food_id']);
            if (! $slot) {
                throw new RuntimeException('Synthetic published recipe missing.');
            }
            $format = DB::table('recipe_batch_formats')->where('recipe_version_id', $slot->recipe_version_id)->where('code', 'single')->first();
            if (! $format) {
                throw new RuntimeException('Synthetic single recipe format missing.');
            }
            $weekday = (int) now()->isoWeekday();
            $plan = DB::table('meal_plans')->insertGetId(['title' => 'Синтетичний тиждень кухонного помічника', 'week_start_weekday' => $weekday]);
            $planVersion = DB::table('meal_plan_versions')->insertGetId(['meal_plan_id' => $plan]);
            $serving = DB::table('uoms')->where('code', 'serving')->value('id');
            if (! $serving) {
                $serving = DB::table('uoms')->insertGetId(['code' => 'serving', 'kind' => 'serving', 'label' => 'порція']);
            }
            $mealType = DB::table('meal_types')->insertGetId(['code' => 'cook-'.bin2hex(random_bytes(4)), 'title' => 'Обід']);
            for ($day = 1; $day <= 7; $day++) {
                $dayId = DB::table('meal_plan_days')->insertGetId(['meal_plan_version_id' => $planVersion, 'day_number' => $day]);
                $meal = DB::table('meals')->insertGetId(['meal_plan_day_id' => $dayId, 'meal_type_id' => $mealType, 'title' => 'Синтетична страва']);
                DB::table('meal_items')->insert(['meal_id' => $meal, 'food_id' => $slot->food_id, 'quantity' => '1.00', 'uom_id' => $serving,
                    'recipe_version_id' => $slot->recipe_version_id, 'recipe_batch_format_id' => $format->id, 'recipe_portion_divisor' => '1.00']);
            }
            DB::table('meal_plan_versions')->where('id', $planVersion)->update(['status' => 'published', 'version_number' => 1, 'published_at' => now()]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            DB::table('client_meal_plan_assignments')->where('id', $old->id)->delete();
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $planVersion,
                'start_date' => now()->toDateString(), 'end_date' => now()->addDays(6)->toDateString()]);

            $adminId = DB::table('users')->where('role', 'admin')->value('id');
            $actor = App\Models\User::findOrFail($adminId);
            $editor = app(App\Services\Cooking\CookingEditor::class);
            $cooking = app(App\Services\Cooking\CookingTemplates::class);
            $version = $editor->create($actor, ['title' => 'Синтетичний помічник приготування', 'source_plan_version_id' => $planVersion, 'source_week' => 1, 'mode' => 1]);
            $equipment = DB::table('kitchen_equipment')->insertGetId(['name' => 'Синтетична каструля для демо', 'image_url' => 'https://images.example.com/equipment.jpg']);
            $session = $editor->session($actor, $version, 0, ['day' => 1, 'position' => 1, 'estimated_seconds' => 1800,
                'introduction' => 'Це синтетична демонстрація авторського вступу.', 'equipment' => 'Воду додавайте за текстом автора.',
                'equipment_items' => [['equipment_id' => $equipment]],
                'content' => ['schema_version' => 1, 'stages' => [
                    ['id' => 'prepare', 'title' => 'Підготуйте інгредієнти', 'blocks' => [
                        ['id' => 'action', 'type' => 'action', 'parts' => ['Підготуйте опублікований рецепт.']],
                    ]],
                ]]]);
            $pattern = json_decode(DB::table('cooking_template_versions')->where('id', $version)->value('menu_pattern'), true);
            $firstOutput = null;
            $firstRow = DB::table('recipe_ingredients')->where('recipe_version_id', $slot->recipe_version_id)->value('id');
            foreach ($pattern['items'] as $item) {
                $output = $editor->output($actor, $version, (int) DB::table('cooking_template_versions')->where('id', $version)->value('revision'), $session,
                    $slot->recipe_version_id.':'.$format->id.':1.00');
                $editor->allocate($actor, $version, (int) DB::table('cooking_template_versions')->where('id', $version)->value('revision'),
                    (int) $item['day'], (int) $item['meal_position'], (int) $item['item_position'], $output);
                if ($firstOutput === null) {
                    $firstOutput = $output;
                }
            }
            $stages = ['schema_version' => 1, 'stages' => [
                ['id' => 'prepare', 'title' => 'Підготуйте інгредієнти', 'blocks' => [
                    ['id' => 'action', 'type' => 'action', 'parts' => ['Відміряйте ', ['metric' => 'grams', 'sources' => [['output' => $firstOutput, 'row' => $firstRow]]]]],
                    ['id' => 'hint', 'type' => 'hint', 'text' => 'Кількість узято з опублікованого знімка рецепта.'],
                    ['id' => 'photo', 'type' => 'image', 'url' => 'https://images.example.com/step.jpg', 'alt' => 'Крок приготування'],
                    ['id' => 'timer', 'type' => 'timer', 'label' => 'Дайте настоятися', 'seconds' => 120, 'message' => 'Час показано як орієнтир автора.'],
                ]],
                ['id' => 'finish', 'title' => 'Завершіть приготування', 'blocks' => [
                    ['id' => 'finish-text', 'type' => 'action', 'parts' => ['Розкладіть порції за меню. Це тестова авторська інструкція.']],
                ]],
            ]];
            $editor->session($actor, $version, (int) DB::table('cooking_template_versions')->where('id', $version)->value('revision'), [
                'day' => 1, 'position' => 1, 'estimated_seconds' => 1800, 'introduction' => 'Це синтетична демонстрація авторського вступу.',
                'equipment' => 'Воду додавайте за текстом автора.', 'equipment_items' => [['equipment_id' => $equipment]], 'content' => $stages,
            ], $session);
            $revision = (int) DB::table('cooking_template_versions')->where('id', $version)->value('revision');
            $cooking->review($actor, $version, $revision, ['suitability' => true, 'safety' => true, 'manual_parameters' => true, 'no_process_water_tokens' => true]);
            $cooking->publish($actor, $version, $revision);
            $cooking->select($actor, $version, 0);
        });
    } elseif ($mode === 'library') {
        DB::table('library_items')->delete();
        foreach ([
            ['type' => 'article', 'title' => 'Бібліотека: корисна стаття', 'summary' => 'Тільки тестові дані',
                'content_markdown' => '## Безпечний заголовок', 'status' => 'published', 'published_at' => now()],
            ['type' => 'video', 'title' => 'Бібліотека: відео', 'external_url' => 'https://www.youtube.com/watch?v=test',
                'status' => 'published', 'published_at' => now()],
            ['type' => 'playlist', 'title' => 'Прихована чернетка', 'external_url' => 'https://www.youtube.com/',
                'status' => 'draft', 'published_at' => null],
        ] as $row) {
            DB::table('library_items')->insert($row);
        }
    } elseif ($mode === 'revoke') {
        DB::table('auth_session_states')->where('user_id', $user)->whereNull('revoked_at')->update(['revoked_at' => DB::raw('clock_timestamp()')]);
    } elseif ($mode === 'empty') {
        DB::table('client_meal_plan_assignments')->where('client_id', $client)->delete();
    } elseif (in_array($mode, ['replace', 'reset'], true)) {
        DB::transaction(function () use ($client, $mode): void {
            DB::table('client_meal_plan_assignments')->where('client_id', $client)->delete();
            $fixture = new class
            {
                use ClientMenuFixture;
            };
            $f = $fixture->menuVersion($mode === 'reset' ? 14 : 7, $mode === 'reset' ? 1 : (int) now()->isoWeekday(), $client, static function (array $batch): void {
                DB::table('recipe_versions')->where('id', $batch['version'])->update(['title' => 'Рис із овочами · синтетичний рецепт', 'lock_version' => DB::raw('lock_version + 1')]);
                $litre = DB::table('uoms')->insertGetId(['code' => 'recipe-browser-'.bin2hex(random_bytes(4)), 'kind' => 'volume', 'label' => 'л']);
                $tool = DB::table('kitchen_equipment')->insertGetId(['name' => 'Каструля · '.bin2hex(random_bytes(4))]);
                $equipment = DB::table('recipe_kitchen_equipment')->insertGetId(['recipe_version_id' => $batch['version'], 'kitchen_equipment_id' => $tool, 'min_value' => '1.00', 'uom_id' => $litre]);
                foreach ($batch['formats'] as $code => $format) {
                    DB::table('recipe_batch_equipment')->insert(['recipe_version_id' => $batch['version'], 'format_id' => $format,
                        'recipe_kitchen_equipment_id' => $equipment, 'mode' => 'manual', 'min_value' => $code === 'single' ? '1.00' : '5.00',
                        'uom_id' => $litre, 'tip' => 'Використовуйте посуд із кришкою.', 'input_snapshot' => '{"schema_version":1}', 'manual_context' => '{}']);
                }
                foreach ([
                    'Промийте рис — {{ingredient:'.$batch['row'].':quantity}}. Покладіть у каструлю й додайте воду за авторською інструкцією.',
                    "Готуйте на слабкому вогні 20 хвилин.\nПеремішайте, додайте овочі й дайте страві настоятися.",
                    'Розділіть готову страву на порції відповідно до обраного формату. Призначену вам кількість показано на початку рецепта.',
                ] as $index => $text) {
                    DB::table('recipe_directions')->insert(['recipe_version_id' => $batch['version'], 'direction_number' => $index + 1,
                        'direction_text' => $text, 'image_url' => $index === 0 ? 'https://images.example.com/step.jpg' : null]);
                }
                DB::update("UPDATE recipe_batch_equipment SET input_snapshot=jsonb_set(input_snapshot,'{adequacy_ack}',to_jsonb(md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text))), manual_context=recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id), review_ack_hash=md5(recipe_batch_equipment_context(recipe_kitchen_equipment_id,format_id)::text) WHERE recipe_version_id=?", [$batch['version']]);
            });
            DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $f['version'], 'start_date' => now()->toDateString()]);
            DB::table('recipes')->where('id', DB::table('recipe_versions')->where('id', $f['batch']['version'])->value('recipe_id'))->update(['image_url' => 'https://images.example.com/batch.jpg']);
            if ($mode === 'reset') {
                DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/t52.jpg']);
            }
        });
    } else {
        throw new RuntimeException('Невідома synthetic дія.');
    }
    echo "T5.2 fixture action complete\n";
    exit;
}
DB::transaction(function () use ($email): void {
    $coach = DB::table('users')->insertGetId(['name' => 'Тестовий тренер', 'role' => 'admin']);
    $user = DB::table('users')->insertGetId(['name' => 'Тестовий клієнт', 'email' => $email, 'email_normalized' => $email, 'email_verified_at' => now()]);
    $client = DB::table('clients')->insertGetId(['user_id' => $user, 'coach_user_id' => $coach]);
    $fixture = new class
    {
        use ClientMenuFixture;
    };
    $f = $fixture->menuVersion(14);
    DB::table('client_meal_plan_assignments')->insert(['client_id' => $client, 'meal_plan_version_id' => $f['version'], 'start_date' => now()->toDateString()]);
    // HTTPS logical cover: browser test окремо перевіряє успішне й пошкоджене фото.
    DB::table('recipes')->where('id', $f['legacy']['recipe'])->update(['image_url' => 'https://images.example.com/t52.jpg']);
});
echo "T5.2 synthetic browser fixture ready\n";
