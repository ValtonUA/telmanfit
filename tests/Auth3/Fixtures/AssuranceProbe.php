<?php

namespace Tests\Auth3\Fixtures;

use App\Models\Client;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/** Лише тестовий компонент; жодних production routes чи backdoors. */
class AssuranceProbe extends Component
{
    public string $value = '';

    public function rejectValidation(): void
    {
        throw ValidationException::withMessages(['value' => 'Синтетична помилка валідації.']);
    }

    public function rejectAuthorization(): void
    {
        throw new AuthorizationException('Синтетична відмова Policy.');
    }

    public function missingRecord(): void
    {
        Client::findOrFail(2147483647);
    }

    public function render(): string
    {
        return '<div>AUTH3 synthetic probe</div>';
    }
}
