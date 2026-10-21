<?php

namespace Tests\Feature;

use App\Filament\AvatarProviders\LocalAvatarProvider;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AdminLocaleTest extends TestCase
{
    public function test_admin_login_uses_ukrainian_even_when_application_default_is_english(): void
    {
        config(['app.locale' => 'en']);
        app()->setLocale('en');
        $this->get('/admin/login')->assertOk()
            ->assertSee('lang="uk"', escape: false)
            ->assertSee('Електронна пошта')->assertSee('Увійти');
        $this->assertSame('uk', app()->getLocale());
        $this->assertSame('Перейти до вмісту', __('filament-panels::layout.skip_to_content.label'));
        $this->assertSame('Бічна навігація', __('filament-panels::layout.navigation.label'));
        $this->assertSame('Навігаційний шлях', __('filament::components/breadcrumbs.label'));
        $this->assertSame('Пошук', __('filament-tables::table.fields.search.label'));
        $this->assertSame('Очистити вибір', __('filament-forms::components.select.actions.clear.label'));
        $this->assertSame('Закрити сповіщення', __('filament-notifications::notification.actions.close.label'));
    }

    public function test_reachable_form_validation_messages_are_ukrainian(): void
    {
        $this->get('/admin/login')->assertOk();
        $errors = Validator::make(['email' => 'invalid', 'status' => 'invalid'], [
            'email' => 'email', 'coach_user_id' => 'required', 'status' => 'in:pending,active,paused,finished',
        ])->errors();
        $this->assertSame('Поле «Відповідальний тренер» обов’язкове.', $errors->first('coach_user_id'));
        $this->assertStringContainsString('дійсну адресу електронної пошти', $errors->first('email'));
        $this->assertSame('Обране значення поля «Статус супроводу» недійсне.', $errors->first('status'));
    }

    public function test_panel_avatar_is_a_local_asset_for_any_name(): void
    {
        $panel = Filament::getPanel('admin');
        $this->assertSame(LocalAvatarProvider::class, $panel->getDefaultAvatarProvider());
        foreach (['Тестовий Адміністратор', '', '<script>'] as $name) {
            $avatar = app(LocalAvatarProvider::class)->get(new User(['name' => $name]));
            $this->assertSame(url('/images/admin-avatar.svg'), $avatar);
        }
        $this->assertFileExists(public_path('images/admin-avatar.svg'));
    }
}
