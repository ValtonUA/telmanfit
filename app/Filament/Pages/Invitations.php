<?php

namespace App\Filament\Pages;

use App\Services\Auth\Client\ControlledAuth;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class Invitations extends Page
{
    protected string $view = 'filament.pages.invitations';

    protected static ?string $title = 'Запрошення';

    public static function canAccess(): bool
    {
        try {
            app(ControlledAuth::class)->assert();

            return Auth::guard('admin')->user()?->role === 'admin';
        } catch (\Throwable) {
            return false;
        }
    }

    public function invitations(): Collection
    {
        abort_unless(self::canAccess(), 403);

        return DB::table('client_invitations as i')->join('users as u', 'u.id', '=', 'i.coach_user_id')
            ->orderByDesc('i.id')->limit(100)->get(['i.id', 'u.name', 'i.created_at', 'i.consumed_at', 'i.revoked_at']);
    }

    public function coaches(): Collection
    {
        abort_unless(self::canAccess(), 403);

        return DB::table('users')->whereIn('role', ['admin', 'coach'])->orderBy('id')->pluck('name', 'id');
    }
}
