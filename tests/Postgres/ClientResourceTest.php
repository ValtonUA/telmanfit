<?php

namespace Tests\Postgres;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class ClientResourceTest extends PostgresTestCase
{
    protected User $admin;

    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();
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
    public function test_admin_creates_without_account_in_each_status(string $status): void
    {
        $this->travelTo(now()->startOfSecond());
        Livewire::test(CreateClient::class)
            ->fillForm(['coach_user_id' => $this->coach->id, 'user_id' => null, 'status' => $status])
            ->call('create')->assertHasNoFormErrors();

        $client = Client::sole();
        $this->assertNull($client->user_id);
        $this->assertSame($status, $client->status);
        $this->assertSame($this->coach->id, $client->coach->id);
        $this->assertSame($client->id, $this->coach->coachedClients->sole()->id);
        if ($status === 'active') {
            $this->assertTrue($client->coaching_started_at->equalTo(now()));
        } else {
            $this->assertNull($client->coaching_started_at);
        }
        foreach (['client_onboardings', 'client_nutrition_profiles', 'client_activity_profiles', 'client_settings'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_explicit_admin_coach_and_pending_default_are_supported(): void
    {
        Livewire::test(CreateClient::class)
            ->assertFormSet(['status' => 'pending', 'coach_user_id' => null, 'user_id' => null])
            ->fillForm(['coach_user_id' => $this->admin->id])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame($this->admin->id, Client::sole()->coach_user_id);
        $this->assertSame('pending', Client::sole()->status);
        $this->assertNull(Client::sole()->coaching_started_at);
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
    public function test_invalid_create_and_edit_are_rejected(string $field, mixed $value): void
    {
        $data = ['coach_user_id' => $this->coach->id, $field => $value];
        Livewire::test(CreateClient::class)->fillForm($data)->call('create')->assertHasFormErrors([$field]);
        $this->assertSame(0, Client::count());
        $client = Client::create(['coach_user_id' => $this->coach->id]);
        $original = $client->refresh()->getAttributes();
        Livewire::test(EditClient::class, ['record' => $client->id])
            ->fillForm([$field => $value])->call('save')->assertHasFormErrors([$field]);
        $this->assertSame($original, $client->refresh()->getAttributes());
    }

    public function test_client_role_cannot_be_selected_as_coach_even_with_forged_id(): void
    {
        $account = $this->user('client', 'Ineligible');
        Livewire::test(CreateClient::class)
            ->fillForm(['coach_user_id' => $account->id])->call('create')->assertHasFormErrors(['coach_user_id']);
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
        Livewire::test(CreateClient::class)
            ->fillForm(['coach_user_id' => $this->coach->id, 'user_id' => $account->id])
            ->call('create')->assertHasFormErrors(['user_id']);
        $second = Client::create(['coach_user_id' => $this->coach->id]);
        Client::create(['coach_user_id' => $this->coach->id]);
        Livewire::test(EditClient::class, ['record' => $second->id])
            ->fillForm(['user_id' => $account->id])->call('save')->assertHasFormErrors(['user_id']);
        $this->assertModelValidation(['coach_user_id' => $this->coach->id, 'user_id' => $account->id], 'user_id');
        $this->assertSame(3, Client::count());
    }

    public function test_self_coaching_is_rejected(): void
    {
        Livewire::test(CreateClient::class)
            ->fillForm(['coach_user_id' => $this->admin->id, 'user_id' => $this->admin->id])
            ->call('create')->assertHasFormErrors(['coach_user_id']);
        $this->assertModelValidation(['coach_user_id' => $this->admin->id, 'user_id' => $this->admin->id], 'coach_user_id');
        $this->assertSame(0, Client::count());
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
        foreach (['/admin/clients', '/admin/clients/create', '/admin/clients/'.$client->id, '/admin/clients/'.$client->id.'/edit'] as $url) {
            $this->get($url)->assertStatus($status);
        }
        $this->assertSame($role === 'admin', ClientResource::canViewAny());
        $this->assertSame($role === 'admin', ClientResource::canCreate());
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
