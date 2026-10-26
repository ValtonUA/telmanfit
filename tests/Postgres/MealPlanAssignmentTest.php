<?php

namespace Tests\Postgres;

use App\Filament\Resources\Clients\Pages\ManageMealPlanAssignments;
use App\Models\ClientMealPlanAssignment;
use App\Models\User;
use App\Services\MealPlans\MealPlanAssigner;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\MealPlans\AssignmentFixture;

class MealPlanAssignmentTest extends PostgresTestCase
{
    use AssignmentFixture;

    private User $actor;

    private int $client;

    private int $version;

    private MealPlanAssigner $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        $this->travelTo(now()->setDate(2026, 10, 26)->startOfDay()->addHours(12));
        $this->actor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний admin T4.5', 'role' => 'admin']));
        $this->client = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
        $this->version = $this->version();
        $this->service = app(MealPlanAssigner::class);
        $this->actingAs($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function version(?int $owner = null, string $status = 'published'): int
    {
        return $this->assignmentVersion($owner, $status);
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['meal_plan_version_id' => $this->version, 'start_date' => '2026-10-26', 'end_date' => null,
            'expected' => $this->service->snapshot($this->actor, $this->client)], $overrides);
    }

    private function assign(array $input): ClientMealPlanAssignment
    {
        return $this->service->assign($this->actor, $this->client, $input, $this->service->preview($this->actor, $this->client, $input)['confirmation']);
    }

    private function unchanged(callable $command, string $class = ValidationException::class): void
    {
        $before = $this->history();
        try {
            $command();
            $this->fail('Очікувалась відмова без запису.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
        $this->assertSame($before, $this->history());
    }

    private function history(): string
    {
        return json_encode([DB::table('client_meal_plan_assignments')->orderBy('id')->get(), DB::table('client_events')->orderBy('id')->get(),
            DB::table('meal_plan_versions')->orderBy('id')->get()], JSON_THROW_ON_ERROR);
    }

    public function test_first_today_and_adjacent_future_assignment_preserve_exact_versions_and_events(): void
    {
        $a = $this->assign($this->input(['end_date' => '2026-10-26']));
        $b = $this->assign($this->input(['start_date' => '2026-10-27', 'meal_plan_version_id' => $this->version($this->client)]));
        $this->assertNull($b->end_date);
        $this->assertNull($a->fresh()->replaced_by_assignment_id);
        $this->assertSame($this->version, $a->meal_plan_version_id);
        $this->assertSame(2, DB::table('client_events')->count());
        $event = DB::table('client_events')->orderByDesc('id')->first();
        $payload = json_decode($event->event_data, true);
        $this->assertSame($this->client, $event->client_id);
        $this->assertSame($this->actor->id, $event->actor_user_id);
        $this->assertSame($b->id, $payload['new_assignment_id']);
        $this->assertNull($payload['old_assignment_id']);
    }

    public function test_replacement_shortens_only_target_and_logs_all_dates_without_changing_publications(): void
    {
        $old = $this->assign($this->input(['end_date' => '2026-11-05']));
        $later = $this->assign($this->input(['start_date' => '2026-11-10', 'end_date' => '2026-11-20']));
        $input = $this->input(['replace_assignment_id' => $old->id, 'start_date' => '2026-10-27', 'end_date' => '2026-11-05']);
        $before = DB::table('meal_plan_versions')->get()->toJson();
        $preview = $this->service->preview($this->actor, $this->client, $input);
        $this->assertSame('2026-10-26', $preview['old_end_date_after']);
        $this->assertSame(2, DB::table('client_events')->count());
        $new = $this->assign($input);
        $this->assertSame($new->id, $old->fresh()->replaced_by_assignment_id);
        $this->assertSame('2026-10-26', $old->fresh()->end_date->toDateString());
        $this->assertSame('2026-11-10', $later->fresh()->start_date->toDateString());
        $this->assertNull($later->fresh()->replaced_by_assignment_id);
        $payload = json_decode(DB::table('client_events')->orderByDesc('id')->value('event_data'), true);
        foreach (['old_assignment_id', 'old_end_date_before', 'old_end_date_after', 'new_start_date', 'new_end_date'] as $key) {
            $this->assertSame($preview[$key], $payload[$key]);
        }
        $this->assertSame($before, DB::table('meal_plan_versions')->get()->toJson());
    }

    public function test_nonoverlapping_replacement_intent_creates_separate_assignment(): void
    {
        $old = $this->assign($this->input(['end_date' => '2026-10-27']));
        $new = $this->assign($this->input(['replace_assignment_id' => $old->id, 'start_date' => '2026-10-28']));
        $this->assertNull($old->fresh()->replaced_by_assignment_id);
        $this->assertSame('2026-10-27', $old->fresh()->end_date->toDateString());
        $this->assertNull($new->replaced_by_assignment_id);
    }

    public static function invalidPeriods(): array
    {
        return [['2026-10-25', null], ['2026-10-26', '2026-10-25'], ['2026-02-30', null], ['infinity', null], ['2026-10-26T00:00', null]];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_dates_are_rejected(string $start, ?string $end): void
    {
        $this->unchanged(fn () => $this->assign($this->input(['start_date' => $start, 'end_date' => $end])));
    }

    public function test_overlap_today_same_start_and_conflicting_future_are_rejected(): void
    {
        $old = $this->assign($this->input(['end_date' => '2026-11-05']));
        $this->assign($this->input(['start_date' => '2026-11-10', 'end_date' => '2026-11-20']));
        foreach ([[], ['replace_assignment_id' => $old->id], ['replace_assignment_id' => $old->id, 'start_date' => '2026-10-27'],
            ['replace_assignment_id' => $old->id, 'start_date' => '2026-11-06'], ['start_date' => '2026-11-05', 'end_date' => '2026-11-05']] as $overrides) {
            $this->unchanged(fn () => $this->assign($this->input($overrides)));
        }
        $scheduled = $this->assign($this->input(['start_date' => '2026-11-21']));
        $this->unchanged(fn () => $this->assign($this->input(['replace_assignment_id' => $scheduled->id, 'start_date' => '2026-11-21'])));
    }

    public function test_stale_replay_forged_confirmation_and_changed_input_are_rejected(): void
    {
        $input = $this->input();
        $grant = $this->service->preview($this->actor, $this->client, $input)['confirmation'];
        $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, $input, 'true'));
        $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, array_replace($input, ['start_date' => '2026-10-27']), $grant));
        $this->service->assign($this->actor, $this->client, $input, $grant);
        $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, $input, $grant));
        $input = $this->input(['start_date' => '2026-10-27', 'replace_assignment_id' => ClientMealPlanAssignment::sole()->id]);
        $grant = $this->service->preview($this->actor, $this->client, $input)['confirmation'];
        DB::table('clients')->where('id', $this->client)->update(['status' => 'paused']);
        $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, $input, $grant));
    }

    public function test_exact_published_client_owner_and_cross_client_target_validation(): void
    {
        foreach ([$this->version(null, 'draft'), 2147483647, $this->version(DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]))] as $version) {
            $this->unchanged(fn () => $this->assign($this->input(['meal_plan_version_id' => $version])));
        }
        $this->unchanged(fn () => $this->assign($this->input(['replace_assignment_id' => 2147483647])));
        $this->unchanged(fn () => $this->service->snapshot($this->actor, 2147483647));
    }

    public function test_journal_failure_rolls_back_shortening_link_and_new_assignment(): void
    {
        $old = $this->assign($this->input());
        DB::unprepared("CREATE FUNCTION assignment_event_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic journal failure'; END $$; CREATE TRIGGER assignment_event_fault BEFORE INSERT ON client_events FOR EACH ROW EXECUTE FUNCTION assignment_event_fault();");
        $this->unchanged(fn () => $this->assign($this->input(['start_date' => '2026-10-27', 'replace_assignment_id' => $old->id])), QueryException::class);
        $this->assertNull($old->fresh()->end_date);
    }

    public function test_revoked_admin_coach_client_and_recovery_fence_cannot_write(): void
    {
        $input = $this->input();
        $grant = $this->service->preview($this->actor, $this->client, $input)['confirmation'];
        foreach (['coach', 'client'] as $role) {
            DB::table('users')->where('id', $this->actor->id)->update(['role' => $role]);
            $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, $input, $grant), HttpException::class);
        }
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'admin']);
        DB::table('auth_security_runtime')->where('id', 1)->update(['state' => 'recovery_required']);
        $this->unchanged(fn () => $this->service->assign($this->actor, $this->client, $input, $grant), HttpException::class);
    }

    public function test_livewire_preview_cancel_confirm_replay_and_readonly_history(): void
    {
        $page = Livewire::test(ManageMealPlanAssignments::class, ['record' => $this->client])->assertSuccessful()
            ->mountAction('assignMealPlan')->setActionData(['meal_plan_version_id' => $this->version, 'start_date' => '2026-10-26']);
        $page->callMountedAction()->assertHasNoActionErrors()->assertActionMounted('confirmAssignment');
        $this->assertSame(0, DB::table('client_events')->count());
        $page->unmountAction();
        $this->assertSame(0, DB::table('client_meal_plan_assignments')->count());
        $page->mountAction('assignMealPlan')->setActionData(['meal_plan_version_id' => $this->version, 'start_date' => '2026-10-26'])
            ->callMountedAction()->callMountedAction()->assertHasNoActionErrors();
        $old = ClientMealPlanAssignment::sole();
        $this->assertSame(1, DB::table('client_events')->count());
        $page->callTableAction('replaceMealPlan', $old, ['meal_plan_version_id' => $this->version, 'start_date' => '2026-10-27'])
            ->assertActionMounted('confirmAssignment')->assertSet('assignmentSummary', fn ($summary) => str_contains($summary, 'Завершиться'))->callMountedAction()->assertHasNoActionErrors();
        $this->assertNotNull($old->fresh()->replaced_by_assignment_id);
        $this->assertSame(2, DB::table('client_events')->count());
    }

    public function test_livewire_stale_confirmation_returns_ukrainian_error(): void
    {
        $page = Livewire::test(ManageMealPlanAssignments::class, ['record' => $this->client])->mountAction('assignMealPlan')
            ->setActionData(['meal_plan_version_id' => $this->version, 'start_date' => '2026-10-26'])->callMountedAction();
        $this->assign($this->input(['start_date' => '2026-11-01']));
        $page->callMountedAction()->assertHasActionErrors(['assignment']);
        $this->assertSame(1, DB::table('client_events')->count());
    }

    public function test_database_exclusion_and_unpublished_negatives_remain_enforced(): void
    {
        $this->assign($this->input());
        foreach ([$this->version, $this->version(null, 'draft')] as $version) {
            $this->unchanged(fn () => DB::transaction(function () use ($version): void {
                DB::table('client_meal_plan_assignments')->insert(['client_id' => $this->client, 'meal_plan_version_id' => $version, 'start_date' => '2026-10-26']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }), QueryException::class);
        }
    }

    public function test_deferred_fault_after_journal_also_rolls_back_every_write(): void
    {
        $old = $this->assign($this->input());
        DB::unprepared("CREATE FUNCTION assignment_deferred_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic deferred failure'; END $$; CREATE CONSTRAINT TRIGGER assignment_deferred_fault AFTER INSERT ON client_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION assignment_deferred_fault();");
        $this->unchanged(fn () => $this->assign($this->input(['start_date' => '2026-10-27', 'replace_assignment_id' => $old->id])), QueryException::class);
        $this->assertNull($old->fresh()->end_date);
        $this->assertNull($old->fresh()->replaced_by_assignment_id);
    }

    public function test_cross_client_actor_and_replaced_target_cannot_reuse_confirmation(): void
    {
        $old = $this->assign($this->input());
        $input = $this->input(['replace_assignment_id' => $old->id, 'start_date' => '2026-10-27']);
        $grant = $this->service->preview($this->actor, $this->client, $input)['confirmation'];
        $otherClient = DB::table('clients')->insertGetId(['coach_user_id' => $this->actor->id]);
        $this->unchanged(fn () => $this->service->assign($this->actor, $otherClient, $input, $grant));
        $otherActor = User::findOrFail(DB::table('users')->insertGetId(['name' => 'Синтетичний другий admin', 'role' => 'admin']));
        $this->unchanged(fn () => $this->service->assign($otherActor, $this->client, $input, $grant));
        $this->assign($input);
        $this->unchanged(fn () => $this->assign($this->input(['replace_assignment_id' => $old->id, 'start_date' => '2026-10-28'])));
        $this->unchanged(fn () => $this->assign($this->input(['replace_assignment_id' => 2147483648])));
    }

    public function test_sql_date_order_and_self_replacement_remain_enforced(): void
    {
        $old = $this->assign($this->input());
        foreach ([['end_date' => '2026-10-25'], ['replaced_by_assignment_id' => $old->id]] as $change) {
            $this->unchanged(fn () => DB::transaction(function () use ($old, $change): void {
                DB::table('client_meal_plan_assignments')->where('id', $old->id)->update($change);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            }), QueryException::class);
        }
    }

    public function test_livewire_access_is_rechecked_and_empty_versions_are_explained(): void
    {
        Livewire::test(ManageMealPlanAssignments::class, ['record' => $this->client])->mountAction('assignMealPlan')
            ->assertActionMounted('assignMealPlan');
        DB::table('users')->where('id', $this->actor->id)->update(['role' => 'coach']);
        Livewire::test(ManageMealPlanAssignments::class, ['record' => $this->client])->assertForbidden();
        $this->assertSame(0, DB::table('client_events')->count());
    }
}
