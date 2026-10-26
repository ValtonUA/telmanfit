<x-filament-panels::page>
    <p>URL показується один раз після підтвердження пароля й нового TOTP. Передайте його клієнту особисто. Втрачений URL потрібно відкликати.</p>
    <x-filament::section heading="Створити запрошення">
        <form method="post" action="/admin/auth/invitations/create" class="grid gap-6 max-w-xl">
            @csrf
            <x-filament-forms::field-wrapper id="invite-coach" label="Відповідальний тренер" required>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="invite-coach" name="coach_user_id" required>
                        <option value="">Оберіть відповідального</option>
                        @foreach($this->coaches() as $id => $name)
                            <option value="{{ $id }}">{{ $name }} (#{{ $id }})</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <x-filament-forms::field-wrapper id="create-password" label="Поточний пароль" required>
                <x-filament::input.wrapper>
                    <x-filament::input id="create-password" name="password" type="password" autocomplete="current-password" required />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <x-filament-forms::field-wrapper id="create-code" label="Новий код Authenticator" required>
                <x-filament::input.wrapper>
                    <x-filament::input id="create-code" name="code" inputmode="numeric" autocomplete="one-time-code" required />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <div><x-filament::button type="submit">Створити запрошення</x-filament::button></div>
        </form>
    </x-filament::section>

    <div class="fi-ta-ctn">
        <div class="fi-ta-main fi-ta-content-ctn">
            <table class="fi-ta-table fi-ta-table-stacked-on-mobile">
                <caption class="sr-only">Запрошення клієнтів</caption>
                <thead><tr>
                    @foreach (['ID', 'Тренер', 'Створено', 'Стан'] as $label)
                        <th scope="col" class="fi-ta-header-cell">{{ $label }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="fi-ta-records">
                    @forelse ($this->invitations() as $invitation)
                        <tr class="fi-ta-row">
                            @foreach (['ID' => $invitation->id, 'Тренер' => $invitation->full_name, 'Створено' => $invitation->created_at, 'Стан' => $invitation->consumed_at ? 'Використано' : ($invitation->revoked_at ? 'Відкликано' : 'Чинне')] as $label => $value)
                                <td class="fi-ta-cell">
                                    <div class="fi-ta-cell-label">{{ $label }}</div>
                                    <div class="fi-ta-cell-content">
                                        <div class="fi-ta-text fi-wrapped"><span class="fi-ta-text-item fi-size-sm">{{ $value }}</span></div>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="4" class="fi-ta-header-cell">Запрошень немає</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-filament::section heading="Відкликати запрошення">
        <form method="post" action="/admin/auth/invitations/revoke" class="grid gap-6 max-w-xl">
            @csrf
            <x-filament-forms::field-wrapper id="revoke-id" label="ID запрошення" required>
                <x-filament::input.wrapper>
                    <x-filament::input id="revoke-id" name="invitation_id" type="number" required min="1" />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <x-filament-forms::field-wrapper id="revoke-password" label="Поточний пароль" required>
                <x-filament::input.wrapper>
                    <x-filament::input id="revoke-password" name="password" type="password" autocomplete="current-password" required />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <x-filament-forms::field-wrapper id="revoke-code" label="Новий код Authenticator" required>
                <x-filament::input.wrapper>
                    <x-filament::input id="revoke-code" name="code" inputmode="numeric" autocomplete="one-time-code" required />
                </x-filament::input.wrapper>
            </x-filament-forms::field-wrapper>
            <div><x-filament::button type="submit" color="danger">Відкликати запрошення</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
