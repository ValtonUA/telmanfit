<?php

namespace Tests\Postgres;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ClientResourceTest extends PostgresTestCase
{
    protected User $admin;

    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installAuthSchema();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->admin = $this->user('admin', 'Administrator');
        $this->coach = $this->user('coach', 'Coach');
        $this->actingAs($this->admin);
    }

    public static function statuses(): array
    {
        return array_map(fn (string $status): array => [$status], array_keys(Client::STATUSES));
    }

    #[DataProvider('statuses')]
    public function test_existing_unlinked_cards_remain_editable_in_each_status(string $status): void
    {
        // Фікстура перехідної картки; створення через UI заборонене.
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $this->travelTo(now()->startOfSecond());
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['coach_user_id' => $this->admin->id, 'status' => $status])
            ->call('save')->assertHasNoFormErrors();

        $client->refresh();
        $this->assertNull($client->user_id);
        $this->assertSame($status, $client->status);
        $this->assertSame($this->admin->id, $client->coach->id);
        $this->assertSame($client->id, $this->admin->coachedClients->sole()->id);
        if ($status === 'active') {
            $this->assertTrue($client->coaching_started_at->equalTo(now()));
        } else {
            $this->assertNull($client->coaching_started_at);
        }
    }

    public function test_user_and_pending_profile_do_not_activate_paid_coaching(): void
    {
        $account = $this->user('client');
        $this->assertNull($account->client);
        $client = Client::create(['coach_user_id' => $this->coach->id, 'user_id' => $account->id]);
        $this->assertSame('pending', $client->status);
        $this->assertNull($client->coaching_started_at);
        foreach (['client_onboardings', 'client_nutrition_profiles', 'client_activity_profiles', 'client_settings'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_list_has_no_create_action_and_forged_action_does_not_create_card(): void
    {
        Livewire::test(ListClients::class)
            ->assertActionDoesNotExist('create')
            ->call('mountAction', 'create')
            ->call('callMountedAction');
        $this->assertSame(0, Client::count());
    }

    #[DataProvider('roles')]
    public function test_manual_creation_is_denied_through_http_policy_and_livewire(string $role, int $status): void
    {
        $this->actingAs($this->user($role));
        $this->assertFalse(Gate::allows('create', Client::class));
        $this->assertFalse(ClientResource::canCreate());
        $this->get('/admin/clients/create')->assertForbidden();
        Livewire::test(CreateClient::class)->assertForbidden();
        // Прямий виклик create (зокрема зі старої форми) також не досягає запису.
        try {
            app(CreateClient::class)->create();
            $this->fail('Ручне створення має бути заборонене.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, Client::count());
    }

    public function test_admin_edits_links_account_and_coach_then_keeps_first_start_across_statuses(): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $account = $this->user('client', 'Registered client');
        $this->travelTo(now()->startOfSecond());
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['user_id' => $account->id, 'coach_user_id' => $this->admin->id, 'status' => 'active'])
            ->call('save')->assertHasNoFormErrors();
        $client->refresh();
        $started = $client->coaching_started_at;
        $this->assertTrue($started->equalTo(now()));
        $this->assertSame($account->id, $client->user->id);
        $this->assertSame($client->id, $account->client->id);
        $this->assertSame($this->admin->id, $client->coach->id);
        foreach (['paused', 'active', 'finished', 'active'] as $status) {
            $this->travel(1)->days();
            Livewire::test(EditClient::class, ['record' => $client->id])
                ->fillForm(['status' => $status])->call('save')->assertHasNoFormErrors();
            $this->assertSame($status, $client->refresh()->status);
            $this->assertTrue($client->coaching_started_at->equalTo($started));
        }
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['user_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($client->refresh()->user_id);
    }

    public function test_list_search_filters_and_view_card(): void
    {
        $account = $this->user('client', 'Distinctive client');
        $linked = Client::create(['coach_user_id' => $this->coach->id, 'user_id' => $account->id, 'status' => 'active']);
        $offline = Client::create(['coach_user_id' => $this->admin->id]);
        Livewire::test(ListClients::class)->assertCanSeeTableRecords([$linked, $offline])
            ->searchTable('Distinctive')->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$offline])
            ->searchTable($account->email)->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$offline])
            ->searchTable((string) $offline->id)->assertCanSeeTableRecords([$offline])
            ->searchTable('')->filterTable('status', 'active')
            ->assertCanSeeTableRecords([$linked])->assertCanNotSeeTableRecords([$offline])
            ->filterTable('status', 'pending')
            ->assertCanSeeTableRecords([$offline])->assertCanNotSeeTableRecords([$linked]);
        Livewire::test(ViewClient::class, ['record' => $linked->id])
            ->assertSuccessful()->assertSee('Distinctive client')->assertSee('Активний');
        Livewire::test(ViewClient::class, ['record' => $offline->id])
            ->assertSuccessful()->assertSee('Без акаунта');
    }

    public function test_search_by_coach_and_empty_results(): void
    {
        $assigned = Client::create(['coach_user_id' => $this->coach->id]);
        $other = Client::create(['coach_user_id' => $this->admin->id]);

        Livewire::test(ListClients::class)
            ->searchTable('Coach')->assertCanSeeTableRecords([$assigned])->assertCanNotSeeTableRecords([$other])
            ->searchTable('No matching synthetic client')->assertCountTableRecords(0)
            ->searchTable('')->assertCanSeeTableRecords([$assigned, $other]);
    }

    public function test_invitation_mode_preserves_account_when_edit_state_is_forged(): void
    {
        config(['auth4.enabled' => true]);
        $account = $this->user('client');
        $replacement = $this->user('client');
        $linked = Client::create(['coach_user_id' => $this->coach->id, 'user_id' => $account->id]);
        $unlinked = Client::create(['coach_user_id' => $this->coach->id]);

        foreach ([$linked, $unlinked] as $client) {
            $originalAccount = $client->user_id;
            Livewire::test(EditClient::class, ['record' => $client->id])
                ->assertFormFieldIsDisabled('user_id')
                ->set('data.user_id', $replacement->id)
                ->fillForm(['coach_user_id' => $this->admin->id, 'status' => 'paused'])
                ->call('save')->assertHasNoFormErrors();
            $this->assertSame($originalAccount, $client->refresh()->user_id);
            $this->assertSame($this->admin->id, $client->coach_user_id);
            $this->assertSame('paused', $client->status);
            $this->assertNull($client->coaching_started_at);
        }
    }

    public function test_delete_actions_are_unavailable_and_forged_calls_preserve_card(): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $this->assertFalse(Gate::allows('deleteAny', Client::class));
        Livewire::test(ListClients::class)
            ->assertActionDoesNotExist(TestAction::make('delete')->table($client))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $client->id])
            ->call('callMountedAction');
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->assertActionDoesNotExist('delete')
            ->call('mountAction', 'delete')->call('callMountedAction');
        $this->assertSame(1, Client::whereKey($client->id)->count());
    }

    public function test_admin_can_edit_first_questionnaire_in_existing_client_card(): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);

        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertActionExists('editFirstAnswers')
            ->callAction('editFirstAnswers', [
                'top_reasons' => 'Вечірній голод',
                'workplace' => 'home',
                'meals_per_day' => '3',
            ]);

        $row = DB::table('client_onboardings')->where('client_id', $client->id)->first();
        $this->assertNotNull($row);
        $answers = json_decode($row->answers, true);
        $this->assertSame('Вечірній голод', $answers['top_reasons']);
        $this->assertSame('home', $answers['workplace']);
        $this->assertSame(3, $answers['meals_per_day']);
        $this->assertNull($row->completed_at);
        $this->assertSame('pending', $client->refresh()->status);

        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertSee('Вечірній голод')
            ->callAction('editFirstAnswers', [
                'top_reasons' => 'Проблеми з режимом',
                'workplace' => 'office',
                'meals_per_day' => '2',
            ]);

        $this->assertSame(1, DB::table('client_onboardings')->where('client_id', $client->id)->count());
        $changed = json_decode(DB::table('client_onboardings')->where('client_id', $client->id)->value('answers'), true);
        $this->assertSame('Проблеми з режимом', $changed['top_reasons']);
        $this->assertSame('office', $changed['workplace']);
        $this->assertSame(2, $changed['meals_per_day']);
    }

    #[DataProvider('statuses')]
    public function test_status_labels_and_semantic_colors_survive_search_and_sorting(string $status): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id, 'status' => $status]);
        $color = ['pending' => 'gray', 'active' => 'success', 'paused' => 'warning', 'finished' => 'info'][$status];
        $component = Livewire::test(ListClients::class)
            ->searchTable((string) $client->id)->filterTable('status', $status)->sortTable('id', 'asc')
            ->assertCanSeeTableRecords([$client])
            ->assertTableColumnFormattedStateSet('status', Client::STATUSES[$status], $client)
            ->assertSee($color === 'gray' ? 'fi-badge' : 'fi-color-'.$color)
            ->assertSee('fi-ta-table-stacked-on-mobile');
        $column = $component->instance()->getTable()->getColumn('status')->record($client);
        $this->assertSame($color, $column->getColor($status));
        Livewire::test(ViewClient::class, ['record' => $client->id])
            ->assertSee(Client::STATUSES[$status])->assertSee($color === 'gray' ? 'fi-badge' : 'fi-color-'.$color);
    }

    public static function invalidFields(): array
    {
        return [
            'missing coach' => ['coach_user_id', null],
            'unknown coach' => ['coach_user_id', 9223372036854775807],
            'unknown account' => ['user_id', 9223372036854775807],
            'invalid status' => ['status', 'invalid'],
            'missing status' => ['status', null],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_edits_are_rejected(string $field, mixed $value): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $original = $client->refresh()->getAttributes();
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm([$field => $value])->call('save')->assertHasFormErrors([$field]);
        $this->assertSame($original, $client->refresh()->getAttributes());
    }

    public function test_client_role_cannot_be_selected_as_coach_even_with_forged_id(): void
    {
        $account = $this->user('client', 'Ineligible');
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['coach_user_id' => $account->id])->call('save')->assertHasFormErrors(['coach_user_id']);
        $this->assertSame($this->coach->id, $client->refresh()->coach_user_id);
        $this->assertModelValidation(['coach_user_id' => $account->id], 'coach_user_id');
    }

    public function test_one_card_per_account_but_multiple_cards_without_accounts(): void
    {
        $account = $this->user('client');
        $first = Client::create(['coach_user_id' => $this->coach->id, 'user_id' => $account->id]);
        Livewire::test(EditClient::class, ['record' => $first->id])->call('save')->assertHasNoFormErrors();
        $second = Client::create(['coach_user_id' => $this->coach->id]);
        Client::create(['coach_user_id' => $this->coach->id]);
        Livewire::test(EditClient::class, ['record' => $second->id])
            ->fillForm(['user_id' => $account->id])->call('save')->assertHasFormErrors(['user_id']);
        $this->assertModelValidation(['coach_user_id' => $this->coach->id, 'user_id' => $account->id], 'user_id');
        $this->assertSame(3, Client::count());
    }

    public function test_self_coaching_is_rejected(): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm(['coach_user_id' => $this->admin->id, 'user_id' => $this->admin->id])
            ->call('save')->assertHasFormErrors(['coach_user_id']);
        $this->assertModelValidation(['coach_user_id' => $this->admin->id, 'user_id' => $this->admin->id], 'coach_user_id');
        $this->assertSame($this->coach->id, $client->refresh()->coach_user_id);
        $this->assertNull($client->user_id);
    }

    public function test_model_protects_status_and_required_coach_outside_filament(): void
    {
        $this->assertModelValidation([], 'coach_user_id');
        $this->assertModelValidation(['coach_user_id' => $this->coach->id, 'status' => 'invalid'], 'status');
        $this->assertModelValidation(['coach_user_id' => 9223372036854775807], 'coach_user_id');
        $this->assertModelValidation(['coach_user_id' => $this->coach->id, 'user_id' => 9223372036854775807], 'user_id');
        $client = Client::create(['coach_user_id' => $this->coach->id, 'status' => 'active']);
        $this->assertNotNull($client->coaching_started_at);
    }

    public static function roles(): array
    {
        return [['admin', 200], ['coach', 403], ['client', 403]];
    }

    #[DataProvider('roles')]
    public function test_http_pages_and_policy_authorization(string $role, int $status): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $actor = $this->user($role);
        $this->actingAs($actor);
        foreach (['/admin/clients', '/admin/clients/'.$client->id, '/admin/clients/'.$client->id.'/edit'] as $url) {
            $this->get($url)->assertStatus($status);
        }
        $this->assertSame($role === 'admin', ClientResource::canViewAny());
        $this->assertSame($role === 'admin', Gate::allows('view', $client));
        $this->assertSame($role === 'admin', Gate::allows('update', $client));
        $this->assertFalse(Gate::allows('delete', $client));
        if ($role !== 'admin') {
            Livewire::test(CreateClient::class)->assertForbidden();
            Livewire::test(EditClient::class, ['record' => $client->id])->assertForbidden();
            Livewire::test(ViewClient::class, ['record' => $client->id])->assertForbidden();
            Livewire::test(ListClients::class)->assertForbidden();
        }
    }

    public function test_guest_pages_redirect_to_login(): void
    {
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        auth()->logout();
        foreach (['/admin/clients', '/admin/clients/create', '/admin/clients/'.$client->id, '/admin/clients/'.$client->id.'/edit'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }

    public function test_database_constraints_remain_in_force_when_bypassing_eloquent(): void
    {
        $account = $this->user('client');
        $valid = ['coach_user_id' => $this->coach->id];
        DB::table('clients')->insert($valid + ['user_id' => $account->id]);
        foreach ([
            [$valid + ['status' => 'active'], '23514'],
            [$valid + ['status' => 'invalid'], '23514'],
            [['coach_user_id' => null], '23502'],
            [['coach_user_id' => 9223372036854775807], '23503'],
            [$valid + ['user_id' => $account->id], '23505'],
        ] as [$data, $state]) {
            try {
                DB::transaction(fn () => DB::table('clients')->insert($data));
                $this->fail('Очікувана відмова БД.');
            } catch (QueryException $e) {
                $this->assertSame($state, $e->errorInfo[0]);
            }
        }
    }

    private function user(string $role, string $name = 'Synthetic'): User
    {
        $user = new User;
        $user->forceFill(['name' => $name, 'email' => bin2hex(random_bytes(8)).'@example.test', 'role' => $role])->save();

        return $user;
    }

    private function assertModelValidation(array $data, string $field): void
    {
        try {
            Client::create($data);
            $this->fail('Очікувана помилка валідації моделі.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
