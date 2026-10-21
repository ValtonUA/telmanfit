<?php

namespace Tests\Unit;

use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ізольовані тести доступу до панелі Filament за роллю (без БД і без контейнера Laravel).
 */
class UserPanelAccessTest extends TestCase
{
    public function test_user_implements_filament_user_contract(): void
    {
        $this->assertInstanceOf(FilamentUser::class, new User);
    }

    public function test_admin_can_access_panel(): void
    {
        $this->assertTrue($this->makeUser('admin')->canAccessPanel($this->panel()));
    }

    public function test_coach_cannot_access_panel(): void
    {
        $this->assertFalse($this->makeUser('coach')->canAccessPanel($this->panel()));
    }

    public function test_client_cannot_access_panel(): void
    {
        $this->assertFalse($this->makeUser('client')->canAccessPanel($this->panel()));
    }

    #[DataProvider('unexpectedRoles')]
    public function test_missing_or_unexpected_role_cannot_access_panel(?string $role): void
    {
        $this->assertFalse($this->makeUser($role)->canAccessPanel($this->panel()));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unexpectedRoles(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'different case' => ['Admin'],
            'unknown role' => ['superuser'],
        ];
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = new User(['name' => 'Test', 'email' => 'test@example.com', 'role' => 'admin']);

        $this->assertNull($user->role);
        $this->assertFalse($user->isFillable('role'));
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    private function makeUser(?string $role): User
    {
        $user = new User;
        $user->role = $role;

        return $user;
    }

    private function panel(): Panel
    {
        return $this->createStub(Panel::class);
    }
}
