<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        return 'Картка клієнта #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editFirstAnswers')
                ->label('Анкета — 3 запитання')
                ->visible(fn (): bool => (Filament::auth()->user()?->can('update', $this->getRecord()) ?? false)
                    && DB::table('client_onboardings')->where('client_id', $this->getRecord()->getKey())->value('completed_at') === null)
                ->modalHeading('Перші відповіді клієнта')
                ->schema([
                    Textarea::make('top_reasons')
                        ->label('ТОП-3 причини, що заважають схуднути')
                        ->required()
                        ->maxLength(1000),
                    Select::make('workplace')
                        ->label('Робота з дому чи в офісі')
                        ->options(['home' => 'Повністю з дому', 'hybrid' => 'З дому та офісу', 'office' => 'Повністю в офісі'])
                        ->required(),
                    Select::make('meals_per_day')
                        ->label('Зручна кількість прийомів їжі')
                        ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                        ->required(),
                ])
                ->fillForm(function (): array {
                    $raw = DB::table('client_onboardings')->where('client_id', $this->getRecord()->getKey())->value('answers');
                    $answers = is_string($raw) ? json_decode($raw, true) : $raw;

                    return is_array($answers) ? $answers : [];
                })
                ->action(function (array $data): void {
                    $client = $this->getRecord();
                    abort_unless(Filament::auth()->user()?->can('update', $client), 403);

                    DB::transaction(function () use ($client, $data): void {
                        DB::table('clients')->where('id', $client->getKey())->lockForUpdate()->firstOrFail();
                        $row = DB::table('client_onboardings')->where('client_id', $client->getKey())->first();
                        if ($row?->completed_at !== null) {
                            throw ValidationException::withMessages(['answers' => 'Завершена анкета є незмінним архівом.']);
                        }
                        $existing = is_string($row?->answers) ? json_decode($row->answers, true) : $row?->answers;
                        $answers = array_replace(is_array($existing) ? $existing : [], [
                            'top_reasons' => trim((string) $data['top_reasons']),
                            'workplace' => $data['workplace'],
                            'meals_per_day' => (int) $data['meals_per_day'],
                        ]);

                        if ($row) {
                            DB::table('client_onboardings')->where('client_id', $client->getKey())->update([
                                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ]);
                        } else {
                            DB::table('client_onboardings')->insert([
                                'client_id' => $client->getKey(),
                                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ]);
                        }
                    });

                    Notification::make()->success()->title('Перші відповіді збережено')->send();
                }),
            EditAction::make(),
        ];
    }
}
