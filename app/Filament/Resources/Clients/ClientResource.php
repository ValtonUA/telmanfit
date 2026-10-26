<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'картка клієнта';

    protected static ?string $pluralModelLabel = 'Картки клієнтів';

    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->disabled(fn () => (bool) config('auth4.enabled'))
                ->dehydrated(fn () => ! config('auth4.enabled'))
                ->label('Акаунт клієнта')
                ->relationship('user', 'name')
                ->getOptionLabelFromRecordUsing(fn (User $record): string => self::userLabel($record))
                ->searchable(['first_name', 'last_name', 'name', 'email'])
                ->wrapOptionLabels()
                ->unique(ignoreRecord: true)
                ->helperText('Для наявної перехідної картки акаунт може бути відсутній; картка визначається її ID.'),
            Select::make('coach_user_id')
                ->label('Відповідальний тренер')
                ->relationship('coach', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('role', [User::ROLE_COACH, User::ROLE_ADMIN]))
                ->getOptionLabelFromRecordUsing(fn (User $record): string => self::userLabel($record))
                ->searchable(['first_name', 'last_name', 'name', 'email'])
                ->wrapOptionLabels()
                ->required()
                ->rules([Rule::exists('users', 'id')->whereIn('role', [User::ROLE_COACH, User::ROLE_ADMIN])])
                ->helperText('Оберіть відповідального тренера або адміністратора.'),
            Select::make('status')->label('Статус супроводу')
                ->options(Client::STATUSES)->default('pending')->required()
                ->rules([Rule::in(array_keys(Client::STATUSES))])
                ->helperText('Початок супроводу фіксується автоматично при першому переході в активний статус.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('ID картки'),
            TextEntry::make('user.full_name')->label('Клієнт')->placeholder('Без акаунта'),
            TextEntry::make('user.email')->label('Електронна пошта')->placeholder('—'),
            TextEntry::make('coach.full_name')->label('Відповідальний тренер'),
            TextEntry::make('status')->label('Статус супроводу')->badge()
                ->color(fn (string $state): string => self::statusColor($state))
                ->formatStateUsing(fn (string $state): string => Client::STATUSES[$state]),
            TextEntry::make('onboarding_answers')
                ->label('Анкета — перші відповіді')
                ->state(fn (Client $record): string => self::onboardingSummary($record))
                ->columnSpanFull(),
            TextEntry::make('coaching_started_at')->label('Початок супроводу (UTC)')->dateTime()->timezone('UTC')->placeholder('Ще не розпочато'),
            TextEntry::make('created_at')->label('Створено (UTC)')->dateTime()->timezone('UTC'),
            TextEntry::make('updated_at')->label('Оновлено (UTC)')->dateTime()->timezone('UTC'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID картки')->searchable()->sortable()->extraCellAttributes(['class' => 'client-id']),
            TextColumn::make('user.full_name')->label('Клієнт')->searchable(['first_name', 'last_name', 'name'])->sortable(['first_name', 'last_name', 'name'])->wrap()->placeholder('Без акаунта')
                ->extraCellAttributes(['class' => 'client-name']),
            TextColumn::make('user.email')->label('Електронна пошта')->searchable()->wrap()->visibleFrom('sm')->placeholder('—')
                ->extraCellAttributes(['class' => 'client-email']),
            TextColumn::make('coach.full_name')->label('Тренер')->searchable(['first_name', 'last_name', 'name'])->sortable(['first_name', 'last_name', 'name'])->wrap()
                ->extraCellAttributes(['class' => 'client-coach']),
            TextColumn::make('status')->label('Статус')->badge()
                ->extraCellAttributes(['class' => 'client-status'])
                ->color(fn (string $state): string => self::statusColor($state))
                ->formatStateUsing(fn (string $state): string => Client::STATUSES[$state]),
            TextColumn::make('coaching_started_at')->label('Початок (UTC)')->dateTime('d.m.Y H:i')->timezone('UTC')->sortable()->visibleFrom('sm')->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->label('Статус супроводу')->options(Client::STATUSES),
        ])->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading('Клієнтів не знайдено')
            ->emptyStateDescription('Змініть пошук або фільтри. Нові клієнти отримують доступ за запрошенням.')
            ->stackedOnMobile()
            ->extraAttributes(['class' => 'clients-table'])
            ->defaultSort('id', 'desc');
    }

    private static function onboardingSummary(Client $client): string
    {
        $raw = DB::table('client_onboardings')->where('client_id', $client->getKey())->value('answers');
        $answers = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($answers) || ! isset($answers['workplace'], $answers['meals_per_day'], $answers['top_reasons'])) {
            return 'Перші відповіді ще не збережено';
        }

        $workplaces = [
            'home' => 'Повністю з дому',
            'hybrid' => 'З дому та офісу',
            'office' => 'Повністю в офісі',
        ];
        $where = $workplaces[$answers['workplace']] ?? 'Не вказано';

        return 'Робота: '.$where
            .' | Прийомів їжі: '.(string) $answers['meals_per_day']
            .' | Перешкоди: '.(string) $answers['top_reasons'];
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'paused' => 'warning',
            'finished' => 'info',
            default => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    private static function userLabel(User $user): string
    {
        return $user->full_name.' (#'.$user->id.')'.($user->email ? ' — '.$user->email : '');
    }
}
