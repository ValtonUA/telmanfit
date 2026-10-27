<?php

use Illuminate\Support\Facades\DB;
use Tests\MealPlans\ClientMenuFixture;

require __DIR__.'/../pg/auth2-bootstrap.php';

$mode = $argv[1] ?? 'setup';
$email = 't52-browser@example.invalid';
if ($mode !== 'setup') {
    $user = DB::table('users')->where('email_normalized', $email)->value('id');
    $client = DB::table('clients')->where('user_id', $user)->value('id');
    if ($mode === 'library') {
        DB::table('library_items')->delete();
        DB::table('library_items')->insert([
            ['type' => 'article', 'title' => 'Бібліотека: корисна стаття', 'summary' => 'Тільки тестові дані',
                'content_markdown' => '## Безпечний заголовок', 'status' => 'published', 'published_at' => now()],
            ['type' => 'video', 'title' => 'Бібліотека: відео', 'external_url' => 'https://www.youtube.com/watch?v=test',
                'status' => 'published', 'published_at' => now()],
            ['type' => 'playlist', 'title' => 'Прихована чернетка', 'external_url' => 'https://www.youtube.com/',
                'status' => 'draft', 'published_at' => null],
        ]);
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
