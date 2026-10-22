<x-filament-panels::page>
    <p>URL показується один раз після підтвердження пароля й нового TOTP. Передайте його клієнту особисто. Втрачений URL потрібно відкликати.</p>
    <form method="post" action="/admin/auth/invitations/create" class="grid gap-3 max-w-xl">
        @csrf
        <label>Відповідальний тренер<select name="coach_user_id" required><option value="">Оберіть відповідального</option>@foreach($this->coaches() as $id => $name)<option value="{{ $id }}">{{ $name }} (#{{ $id }})</option>@endforeach</select></label>
        <label>Поточний пароль<input name="password" type="password" autocomplete="current-password" required></label>
        <label>Новий код Authenticator<input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
        <x-filament::button type="submit">Створити запрошення</x-filament::button>
    </form>
    <table class="w-full text-left">
        <thead><tr><th>ID</th><th>Тренер</th><th>Створено</th><th>Стан</th></tr></thead>
        <tbody>@foreach ($this->invitations() as $invitation)
            <tr><td>{{ $invitation->id }}</td><td>{{ $invitation->name }}</td><td>{{ $invitation->created_at }}</td><td>{{ $invitation->consumed_at ? 'Використано' : ($invitation->revoked_at ? 'Відкликано' : 'Чинне') }}</td></tr>
        @endforeach</tbody>
    </table>
    <form method="post" action="/admin/auth/invitations/revoke" class="grid gap-3 max-w-xl">
        @csrf
        <label>ID запрошення<input name="invitation_id" type="number" required min="1"></label>
        <label>Поточний пароль<input name="password" type="password" autocomplete="current-password" required></label>
        <label>Новий код Authenticator<input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
        <x-filament::button type="submit">Відкликати запрошення</x-filament::button>
    </form>
</x-filament-panels::page>
