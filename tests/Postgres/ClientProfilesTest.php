<?php

namespace Tests\Postgres;

use App\Models\ClientActivityProfile;
use App\Models\ClientNutritionProfile;
use App\Models\ClientProfile;
use App\Models\ClientSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientProfilesTest extends PostgresTestCase
{
    private function upgrade(): void
    {
        (require database_path('migrations/2026_10_24_000000_revise_client_profiles.php'))->up();
    }

    private function client(): int
    {
        $coach = DB::table('users')->insertGetId(['name' => 'Неоднозначне повне ім’я', 'role' => 'coach']);

        return DB::table('clients')->insertGetId(['coach_user_id' => $coach]);
    }

    private function rejected(string $state, callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Очікувана SQL-відмова '.$state);
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0], $e->getMessage());
        }
    }

    public function test_populated_upgrade_preserves_every_legacy_value_and_external_relation(): void
    {
        $client = $this->client();
        $invalid = $this->client();
        $object = $this->client();
        $beforeUser = (array) DB::table('users')->first();
        $beforeClients = DB::table('clients')->orderBy('id')->get()->toArray();
        DB::table('client_nutrition_profiles')->insert(['client_id' => $client, 'goal' => 'Власна ціль', 'preferences' => 'Овочі', 'restrictions' => 'Текст']);
        DB::table('client_activity_profiles')->insert([
            ['client_id' => $client, 'steps_target' => 7000, 'physical_limitations' => 'Коліно', 'preferred_training_days' => '["monday","sunday"]'],
            ['client_id' => $invalid, 'steps_target' => null, 'physical_limitations' => null, 'preferred_training_days' => '["monday",null,"monday","понеділок"]'],
            ['client_id' => $object, 'steps_target' => null, 'physical_limitations' => null, 'preferred_training_days' => '{"day":"monday"}'],
        ]);
        DB::table('client_settings')->insert(['client_id' => $client, 'timezone' => 'America/New_York', 'reminder_settings' => '{"unknown":42}', 'report_settings' => '{"custom":true}']);
        $onboarding = DB::table('client_onboardings')->insertGetId(['client_id' => $client, 'answers' => '{"future": [3, 1], "top_reasons":"Архів"}', 'completed_at' => now()]);
        $equipment = DB::table('fitness_equipment')->insertGetId(['name' => 'Синтетична гантель']);
        DB::table('client_fitness_equipment')->insert(['client_id' => $client, 'fitness_equipment_id' => $equipment, 'quantity' => 2, 'note' => 'Зберегти']);
        DB::table('client_events')->insert(['client_id' => $client, 'event_type' => 'synthetic']);
        DB::table('recipes')->insert(['title' => 'Synthetic recipe', 'owner_client_id' => $client]);
        DB::table('meal_plans')->insert(['title' => 'Synthetic plan', 'owner_client_id' => $client]);
        $metric = DB::table('metrics')->insertGetId(['code' => 'synthetic_weight', 'name' => 'Synthetic']);
        DB::table('client_metrics')->insert(['client_id' => $client, 'metric_id' => $metric, 'value' => 70, 'measured_at' => now()]);
        $snapshots = [];
        foreach (['recipes', 'meal_plans', 'client_metrics', 'client_fitness_equipment', 'fitness_equipment', 'client_events'] as $table) {
            $snapshots[$table] = DB::table($table)->get()->toArray();
        }
        $tables = ['client_nutrition_profiles', 'client_activity_profiles', 'client_settings', 'client_onboardings', 'client_fitness_equipment', 'fitness_equipment', 'client_events', 'recipes', 'meal_plans', 'client_metrics'];
        $counts = array_map(fn ($table) => DB::table($table)->count(), $tables);
        $answers = DB::table('client_onboardings')->value('answers');
        $this->upgrade();
        foreach ($snapshots as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->get()->toArray(), $table);
        }
        $this->assertSame($counts, array_map(fn ($table) => DB::table($table)->count(), $tables));
        foreach ($beforeUser as $key => $value) {
            $this->assertSame($value, DB::table('users')->first()->$key);
        }
        $user = User::query()->first();
        $this->assertNull($user->first_name);
        $this->assertNull($user->last_name);
        $this->assertSame($beforeUser['name'], $user->full_name);
        $this->assertEquals($beforeClients, DB::table('clients')->orderBy('id')->get()->toArray());
        $this->assertSame('Власна ціль', DB::table('client_profiles')->value('goal'));
        $this->assertSame('Власна ціль', DB::table('client_nutrition_profiles')->value('legacy_goal'));
        $this->assertSame('Овочі', DB::table('client_nutrition_profiles')->value('preferences'));
        $this->assertSame(['monday', 'sunday'], ClientActivityProfile::findOrFail($client)->preferred_training_days);
        $this->assertNull(ClientActivityProfile::findOrFail($invalid)->preferred_training_days);
        $this->assertNull(ClientActivityProfile::findOrFail($object)->preferred_training_days);
        $this->assertSame('["monday", null, "monday", "понеділок"]', DB::table('client_activity_profiles')->where('client_id', $invalid)->value('legacy_preferred_training_days'));
        $this->assertSame('{"unknown": 42}', DB::table('client_settings')->value('reminder_settings'));
        $this->assertSame('{"custom": true}', DB::table('client_settings')->value('report_settings'));
        $this->assertSame('America/New_York', DB::table('client_settings')->value('timezone'));
        $this->assertSame($answers, DB::table('client_onboardings')->value('answers'));
        $this->assertSame($onboarding, DB::table('client_onboardings')->value('id'));
        $this->assertSame('Зберегти', DB::table('client_fitness_equipment')->value('note'));
        $this->assertSame(2, DB::table('client_fitness_equipment')->value('quantity'));
        $this->assertSame('json', DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema='public' AND table_name='client_onboardings' AND column_name='answers'")->data_type);
    }

    public function test_profile_constraints_and_cascade(): void
    {
        $client = $this->client();
        $this->upgrade();
        foreach (['client_profiles', 'client_nutrition_profiles', 'client_activity_profiles', 'client_settings', 'client_onboardings'] as $table) {
            DB::table($table)->insert(['client_id' => $client]);
            $this->rejected('23505', fn () => DB::table($table)->insert(['client_id' => $client]));
            $this->rejected('23503', fn () => DB::table($table)->insert(['client_id' => 2147483647]));
        }
        foreach (['sex' => 'unknown', 'work_mode' => 'home', 'height_cm' => 0, 'birth_date' => 'tomorrow'] as $field => $value) {
            $this->rejected('23514', fn () => DB::table('client_profiles')->where('client_id', $client)->update([$field => $value]));
        }
        foreach (['maintenance_calories', 'target_calories', 'weekly_free_calories'] as $field) {
            $this->rejected('23514', fn () => DB::table('client_nutrition_profiles')->where('client_id', $client)->update([$field => -1]));
        }
        $this->rejected('23514', fn () => DB::table('client_activity_profiles')->where('client_id', $client)->update(['steps_target' => -1]));
        foreach (['{monday,NULL}', '{monday,monday}', '{Monday}', '{понеділок}', '{{monday},{tuesday}}'] as $days) {
            foreach (['client_nutrition_profiles' => 'preferred_cooking_days', 'client_activity_profiles' => 'preferred_training_days'] as $table => $field) {
                $this->rejected('23514', fn () => DB::table($table)->where('client_id', $client)->update([$field => $days]));
            }
        }
        $this->rejected('23514', fn () => DB::table('client_settings')->where('client_id', $client)->update(['weekly_checkin_day' => 'Monday']));
        $this->rejected('23514', fn () => DB::table('clients')->where('id', $client)->update(['status' => 'active']));
        $this->rejected('23514', fn () => DB::table('clients')->where('id', $client)->update(['status' => 'unknown']));
        DB::table('clients')->where('id', $client)->delete();
        foreach (['client_profiles', 'client_nutrition_profiles', 'client_activity_profiles', 'client_settings', 'client_onboardings'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_models_names_calories_days_and_local_time_round_trip(): void
    {
        $client = $this->client();
        $this->upgrade();
        foreach ([1, 2] as $n) {
            $user = User::create(['first_name' => 'Олена', 'last_name' => 'Коваль']);
            $this->assertSame('Олена Коваль', $user->full_name);
            $this->assertSame('Олена Коваль', $user->getFilamentName());
            $this->assertNull($user->fresh()->getRawOriginal('name'));
        }
        $this->rejected('23514', fn () => DB::table('users')->insert(['first_name' => 'Лише ім’я']));
        ClientProfile::create(['client_id' => $client, 'height_cm' => 173, 'birth_date' => '1990-01-01', 'sex' => 'female', 'work_mode' => 'remote']);
        $nutrition = ClientNutritionProfile::create(['client_id' => $client, 'maintenance_calories' => 2200, 'target_calories' => 1900, 'weekly_free_calories' => 1000, 'preferred_cooking_days' => ['sunday']]);
        $this->assertSame(['sunday'], $nutrition->fresh()->preferred_cooking_days);
        $this->assertSame(1900, $nutrition->fresh()->target_calories);
        $settings = ClientSetting::create(['client_id' => $client, 'timezone' => 'Europe/Kyiv', 'morning_message_time' => '08:30:00', 'weekly_checkin_day' => 'friday']);
        $this->assertSame('08:30:00', $settings->fresh()->morning_message_time);
        foreach ([['height_cm' => 173.5], ['birth_date' => '2099-01-01'], ['sex' => 'other'], ['work_mode' => 'home']] as $change) {
            try {
                ClientProfile::findOrFail($client)->fill($change)->save();
                $this->fail('Очікувана Laravel-валідація');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_models_reject_invalid_calories_steps_days_and_timezone_before_writing(): void
    {
        $client = $this->client();
        $this->upgrade();
        foreach ([
            [ClientNutritionProfile::class, ['maintenance_calories' => -1]],
            [ClientNutritionProfile::class, ['target_calories' => -1]],
            [ClientNutritionProfile::class, ['weekly_free_calories' => -1]],
            [ClientNutritionProfile::class, ['preferred_cooking_days' => ['monday', 'monday']]],
            [ClientActivityProfile::class, ['steps_target' => -1]],
            [ClientActivityProfile::class, ['preferred_training_days' => ['monday', null]]],
            [ClientActivityProfile::class, ['preferred_training_days' => ['Monday']]],
            [ClientSetting::class, ['timezone' => 'invalid']],
            [ClientSetting::class, ['weekly_checkin_day' => 'Monday']],
        ] as [$model, $attributes]) {
            try {
                $model::create(['client_id' => $client] + $attributes);
                $this->fail('Очікувана Laravel-валідація');
            } catch (ValidationException) {
                $this->assertSame(0, $model::query()->count());
            }
        }
    }

    public function test_completed_archive_rejects_changes_and_reopening_without_activation(): void
    {
        $client = $this->client();
        $this->upgrade();
        DB::table('client_onboardings')->insert(['client_id' => $client, 'answers' => '{"kept":true}', 'completed_at' => now()]);
        $before = (array) DB::table('client_onboardings')->where('client_id', $client)->first();
        foreach ([
            ['answers' => '{}'],
            ['completed_at' => null],
            ['client_id' => $this->client()],
            ['updated_at' => '2000-01-01 00:00:00+00'],
            ['id' => $before['id'] + 1000],
            ['answers' => $before['answers']],
            ['updated_at' => $before['updated_at']],
            ['id' => $before['id']],
        ] as $change) {
            $this->rejected('23514', fn () => DB::table('client_onboardings')->where('client_id', $client)->update($change));
            $this->assertSame($before, (array) DB::table('client_onboardings')->where('client_id', $client)->first());
        }
        $this->rejected('23514', fn () => DB::table('client_onboardings')->where('client_id', $client)->delete());
        ClientProfile::create(['client_id' => $client, 'goal' => 'Нова поточна ціль']);
        $this->assertSame('{"kept":true}', DB::table('client_onboardings')->value('answers'));
        $this->assertSame('pending', DB::table('clients')->where('id', $client)->value('status'));
        $this->assertNull(DB::table('clients')->where('id', $client)->value('coaching_started_at'));
        DB::table('clients')->where('id', $client)->delete();
        $this->assertSame(0, DB::table('client_onboardings')->count());
    }

    public function test_draft_can_be_updated_and_completed_but_completion_freezes_the_whole_row(): void
    {
        $client = $this->client();
        $this->upgrade();
        DB::table('client_onboardings')->insert(['client_id' => $client]);
        DB::table('client_onboardings')->where('client_id', $client)->update([
            'answers' => '{"draft":true}', 'updated_at' => '2000-01-01 00:00:00+00',
        ]);
        $this->assertSame('{"draft":true}', DB::table('client_onboardings')->value('answers'));
        DB::table('client_onboardings')->where('client_id', $client)->update([
            'answers' => '{"final":true}', 'completed_at' => now(),
        ]);
        $this->rejected('23514', fn () => DB::statement('UPDATE client_onboardings SET completed_at = completed_at WHERE client_id = ?', [$client]));
        $this->assertSame('{"final":true}', DB::table('client_onboardings')->value('answers'));

        $draft = $this->client();
        DB::table('client_onboardings')->insert(['client_id' => $draft]);
        $this->assertSame(1, DB::table('client_onboardings')->where('client_id', $draft)->delete());
    }
}
