<?php

namespace Tests\Postgres;

use App\Filament\Resources\MealPlans\Pages\ViewMealPlanVersion;
use App\Models\MealItem;
use App\Models\MealPlanVersion;
use App\Models\User;
use App\Services\MealPlans\MealItemResolver;
use App\Services\MealPlans\MealItemWriter;
use App\Services\MealPlans\MealPlanStructure;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\PlanFixture;
use Tests\Recipes\BatchFixture;

class MealItemWriterTest extends PostgresTestCase
{
    use BatchFixture, PlanFixture;

    private User $actor;

    private MealPlanVersion $version;

    private MealItemWriter $writer;

    private array $f;

    private array $b;

    private int $meal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->installMealItemSchema();
        DB::table('uoms')->insert(['code' => 'g', 'kind' => 'mass', 'label' => 'gram']);
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Synthetic item admin', 'role' => 'admin']));
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->f = $this->populatedPlan($this->actor->id);
        $this->b = $this->batch();
        $this->publish($this->b['version']);
        $this->version = $this->legacyEmptyPlan('Writer')->versions()->sole();
        app(MealPlanStructure::class)->generate($this->actor, $this->version, ['start_day' => 1, 'meal_type_ids' => [$this->f['type']]]);
        $this->meal = $this->version->days()->orderBy('id')->first()->meals()->sole()->id;
        $this->writer = app(MealItemWriter::class);
    }

    private function input(?int $food = null, ?MealItem $item = null): array
    {
        $food ??= $this->f['food'];
        $catalog = $this->writer->catalog($this->actor, $this->version, $food);

        return ['meal_id' => $item?->meal_id ?? $this->meal, 'food_id' => $food, 'uom_id' => $catalog['default'], 'quantity' => '0.50', 'sort_order' => 0,
            'expected' => $item ? MealItemWriter::token($item) : $this->writer->mealToken($this->actor, $this->version, $this->meal), 'catalog_expected' => $catalog['token']];
    }

    private function invalid(callable $command): void
    {
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        try {
            $command();
            $this->fail('Очікується validation failure');
        } catch (ValidationException) {
            $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
        }
    }

    public function test_ingredient_default_conversion_decimal_and_stale_catalog(): void
    {
        $u = DB::table('uoms')->insertGetId(['code' => 'writer-piece', 'label' => 'штука', 'kind' => 'count']);
        DB::table('ingredient_uoms')->insert(['ingredient_id' => $this->f['ingredient'], 'uom_id' => $u, 'grams_per_uom' => '33.33', 'is_default' => true]);
        $input = $this->input();
        $this->assertSame($u, $input['uom_id']);
        $preview = $this->writer->preview($this->actor, $this->version, $input);
        $item = $this->writer->save($this->actor, $this->version, $input);
        $this->assertSame('16.67', $item->resolved_quantity_grams);
        $this->assertSame($preview->nutrition, app(MealItemResolver::class)->resolve($item)->nutrition);
        $this->assertNull($item->recipe_version_id);
        $this->invalid(fn () => $this->writer->save($this->actor, $this->version, $input));
        $edit = $this->input(item: $item);
        DB::table('ingredient_uoms')->where('ingredient_id', $this->f['ingredient'])->update(['grams_per_uom' => '40.00']);
        $this->invalid(fn () => $this->writer->save($this->actor, $this->version, $edit, $item->id));
        $edit = $this->input(item: $item);
        $edit['quantity'] = '1.25';
        $this->writer->save($this->actor, $this->version, $edit, $item->id);
        $this->assertSame('50.00', $item->fresh()->resolved_quantity_grams);
        $edit = $this->input(item: $item->fresh());
        $edit['uom_id'] = $this->f['g'];
        $this->writer->save($this->actor, $this->version, $edit, $item->id);
        $this->assertSame('0.50', $item->fresh()->resolved_quantity_grams);
    }

    public function test_legacy_and_all_four_explicit_batch_portions_use_exact_resolver(): void
    {
        $legacy = DB::table('recipe_versions')->where('editor_contract', 'legacy_v1')->where('status', 'published')->sole();
        $item = $this->writer->save($this->actor, $this->version, $this->input($legacy->food_id));
        $this->assertNull($item->resolved_quantity_grams);
        $this->assertNull($item->recipe_version_id);
        $this->assertSame('50.000000', app(MealItemResolver::class)->resolve($item)->nutrition['kcal']);
        $food = DB::table('recipe_versions')->where('id', $this->b['version'])->value('food_id');
        $physical = DB::table('recipe_batch_quantities')->orderBy('format_id')->get()->toJson();
        foreach ([['single', 1, '92.587500'], ['week', 7, '92.587500'], ['half_week', 3, '108.018750'], ['half_week', 4, '81.014063']] as [$code, $divisor, $kcal]) {
            $data = $this->input($food) + ['recipe_version_id' => $this->b['version'], 'recipe_batch_format_id' => $this->b['formats'][$code], 'recipe_portion_divisor' => $divisor];
            $item = $this->writer->save($this->actor, $this->version, $data);
            $this->assertSame($kcal, app(MealItemResolver::class)->resolve($item)->nutrition['kcal']);
            $this->assertSame($this->b['version'], $item->recipe_version_id);
            $this->assertNull($item->resolved_quantity_grams);
            $this->assertSame($physical, DB::table('recipe_batch_quantities')->orderBy('format_id')->get()->toJson());
        }
        $data = $this->input($food, $item) + ['recipe_version_id' => $this->b['version'], 'recipe_batch_format_id' => $this->b['formats']['week'], 'recipe_portion_divisor' => 7];
        $this->writer->save($this->actor, $this->version, $data, $item->id);
        $this->assertSame(7, $item->fresh()->recipe_portion_divisor);
    }

    public function test_invalid_combinations_forged_identity_precision_and_overflow_roll_back(): void
    {
        foreach ([['food_id' => 2147483647], ['uom_id' => 32767], ['quantity' => 0.5], ['quantity' => '0'], ['quantity' => '-1'], ['quantity' => '1.001'], ['quantity' => '100000'], ['quantity' => 'NaN'], ['sort_order' => 32768], ['meal_id' => 2147483647], ['recipe_version_id' => $this->b['version']], ['recipe_batch_format_id' => $this->b['formats']['single']], ['recipe_portion_divisor' => 1]] as $patch) {
            $data = array_replace($this->input(), $patch);
            $this->invalid(fn () => $this->writer->save($this->actor, $this->version, $data));
        }
        $food = DB::table('recipe_versions')->where('id', $this->b['version'])->value('food_id');
        $base = $this->input($food);
        foreach ([[], ['recipe_version_id' => $this->b['version']], ['recipe_version_id' => $this->b['version'], 'recipe_batch_format_id' => $this->b['formats']['week'], 'recipe_portion_divisor' => 1], ['recipe_version_id' => 2147483647, 'recipe_batch_format_id' => $this->b['formats']['single'], 'recipe_portion_divisor' => 1], ['recipe_version_id' => $this->b['version'], 'recipe_batch_format_id' => 2147483647, 'recipe_portion_divisor' => 1], ['uom_id' => $this->f['g']]] as $patch) {
            $this->invalid(fn () => $this->writer->save($this->actor, $this->version, array_replace($base, $patch)));
        }
    }

    public function test_move_sort_delete_stale_cross_version_and_old_week_confirmation(): void
    {
        $s = app(MealPlanStructure::class);
        $snapshot = $s->weekDeletionSnapshots($this->actor, $this->version)[0];
        $item = $this->writer->save($this->actor, $this->version, $this->input());
        $this->invalid(fn () => $s->deleteWeek($this->actor, $this->version, $snapshot));
        $filled = $s->weekDeletionSnapshots($this->actor, $this->version)[0];
        $confirmation = $s->confirmWeekDeletion($this->actor, $this->version, $filled);
        $other = $this->version->days()->where('day_number', 2)->sole()->meals()->sole()->id;
        $expected = MealItemWriter::token($item);
        $this->invalid(fn () => $this->writer->move($this->actor, $this->version, $item->id, $this->f['meal'], 0, $expected));
        $this->writer->move($this->actor, $this->version, $item->id, $other, -5, $expected);
        $this->assertSame($other, $item->fresh()->meal_id);
        $this->assertSame(-5, $item->fresh()->sort_order);
        $this->invalid(fn () => $this->writer->delete($this->actor, $this->version, $item->id, $expected));
        $this->invalid(fn () => $s->deleteWeek($this->actor, $this->version, $filled + $confirmation));
        $this->invalid(fn () => $this->writer->delete($this->actor, $this->version, $this->f['item'], MealItemWriter::token(MealItem::find($this->f['item']))));
        $this->writer->delete($this->actor, $this->version, $item->id, MealItemWriter::token($item->fresh()));
        $this->assertNull($item->fresh());
        $this->invalid(fn () => $this->writer->delete($this->actor, $this->version, $item->id, $expected));
    }

    public function test_livewire_actions_validate_and_persist_through_same_service(): void
    {
        $data = $this->input();
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->callAction('addItem', $data)->assertHasNoActionErrors();
        $item = MealItem::where('meal_id', $this->meal)->sole();
        $data = $this->input(item: $item) + ['item_id' => $item->id];
        $data['quantity'] = '1.25';
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->mountAction('editItem')->setActionData(['item_id' => $item->id])->set('mountedActions.0.data.quantity', '1.25')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('1.25', $item->fresh()->quantity);
        $data = $this->input(item: $item->fresh()) + ['item_id' => $item->id];
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->callAction('moveItem', $data)->assertHasNoActionErrors();
        $data['expected'] = MealItemWriter::token($item->fresh());
        Livewire::test(ViewMealPlanVersion::class, ['record' => $this->version->id])->callAction('deleteItem', $data)->assertHasNoActionErrors();
        $this->assertNull($item->fresh());
    }

    public function test_acl_published_and_fence_deny_service_and_livewire_with_history_hashes(): void
    {
        $input = $this->input();
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        $published = MealPlanVersion::findOrFail($this->f['version']);
        foreach (['addItem', 'editItem', 'moveItem', 'deleteItem'] as $action) {
            Livewire::test(ViewMealPlanVersion::class, ['record' => $published->id])->call('mountAction', $action)->assertForbidden();
        }
        foreach (['client', 'coach', 'admin'] as $role) {
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            if ($role === 'admin') {
                DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
            }
            try {
                $this->writer->save($this->actor, $this->version, $input);
                $this->fail('Admin fence');
            } catch (HttpException|AuthorizationException $e) {
                $this->assertTrue($e instanceof AuthorizationException || $e->getStatusCode() === 403);
            }
        }
        $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
    }

    public function test_old_forms_do_not_revive_after_content_is_restored(): void
    {
        $original = $this->input();
        $structure = app(MealPlanStructure::class);
        $week = $structure->weekDeletionSnapshots($this->actor, $this->version)[0];
        $item = $this->writer->save($this->actor, $this->version, $original);
        $this->writer->delete($this->actor, $this->version, $item->id, MealItemWriter::token($item));
        $this->invalid(fn () => $this->writer->save($this->actor, $this->version, $original));
        $this->invalid(fn () => $structure->deleteWeek($this->actor, $this->version, $week));
        $item = $this->writer->save($this->actor, $this->version, $this->input());
        $old = $this->input(item: $item);
        $this->writer->save($this->actor, $this->version, array_replace($old, ['quantity' => '1.25']), $item->id);
        $this->writer->save($this->actor, $this->version, $this->input(item: $item->fresh()), $item->id);
        $this->assertSame('0.50', $item->fresh()->quantity);
        $this->invalid(fn () => $this->writer->save($this->actor, $this->version, array_replace($old, ['quantity' => '2.00']), $item->id));
    }

    public function test_dependency_change_in_deferred_guard_rolls_back_before_commit(): void
    {
        $input = $this->input();
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        DB::unprepared("CREATE FUNCTION writer_change_catalog() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE ingredients SET kcal_per_100g='199.00' WHERE food_id=NEW.food_id; RETURN NEW; END $$; CREATE CONSTRAINT TRIGGER writer_change_catalog AFTER INSERT ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION writer_change_catalog();");
        $this->invalid(fn () => $this->writer->save($this->actor, $this->version, $input));
        $this->assertSame('100.00', DB::table('ingredients')->where('id', $this->f['ingredient'])->value('kcal_per_100g'));
        $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
    }

    public function test_commands_leave_all_preexisting_published_history_and_catalogs_byte_equivalent(): void
    {
        $before = [];
        foreach (['foods', 'ingredients', 'recipes', 'recipe_versions', 'recipe_ingredients', 'recipe_batch_formats', 'meal_plans', 'meal_plan_versions', 'meal_plan_days', 'meals', 'meal_items', 'client_meal_plan_assignments'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get();
        }
        $item = $this->writer->save($this->actor, $this->version, $this->input());
        $edit = $this->input(item: $item);
        $edit['quantity'] = '2.35';
        $this->writer->save($this->actor, $this->version, $edit, $item->id);
        $this->writer->delete($this->actor, $this->version, $item->id, MealItemWriter::token($item->fresh()));
        foreach ($before as $table => $rows) {
            $this->assertSame(hash('sha256', $rows->toJson()), hash('sha256', DB::table($table)->whereIn('id', $rows->pluck('id'))->orderBy('id')->get()->toJson()), $table);
        }
    }

    public function test_deferred_failure_rolls_back_and_fresh_role_before_commit(): void
    {
        DB::unprepared("CREATE FUNCTION writer_revoke() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN UPDATE users SET role='coach' WHERE id=".$this->actor->id.'; RETURN NEW; END $$; CREATE CONSTRAINT TRIGGER writer_revoke AFTER INSERT ON meal_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION writer_revoke();');
        $input = $this->input();
        $before = DB::table('meal_items')->orderBy('id')->get()->toJson();
        try {
            $this->writer->save($this->actor, $this->version, $input);
            $this->fail('Revoked before commit');
        } catch (HttpException|AuthorizationException $e) {
            $this->assertTrue($e instanceof AuthorizationException || $e->getStatusCode() === 403);
        }
        $this->assertSame('admin', $this->actor->fresh()->role);
        $this->assertSame($before, DB::table('meal_items')->orderBy('id')->get()->toJson());
    }
}
