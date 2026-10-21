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
use Illuminate\Validation\Rule;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $modelLabel = 'картка клієнта';

    protected static ?string $pluralModelLabel = 'Картки клієнтів';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Акаунт клієнта')
                ->relationship('user', 'name')
                ->getOptionLabelFromRecordUsing(fn (User $record): string => self::userLabel($record))
                ->searchable(['name', 'email'])
                ->unique(ignoreRecord: true)
                ->helperText('Необов’язково. До реєстрації клієнта залиште порожнім; картка визначається її ID.'),
            Select::make('coach_user_id')
                ->label('Відповідальний тренер')
                ->relationship('coach', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('role', [User::ROLE_COACH, User::ROLE_ADMIN]))
                ->getOptionLabelFromRecordUsing(fn (User $record): string => self::userLabel($record))
                ->searchable(['name', 'email'])
                ->required()
                ->rules([Rule::exists('users', 'id')->whereIn('role', [User::ROLE_COACH, User::ROLE_ADMIN])])
                ->helperText('Оберіть явно користувача з роллю coach або admin.'),
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
            TextEntry::make('user.name')->label('Клієнт')->placeholder('Без акаунта'),
            TextEntry::make('user.email')->label('Email')->placeholder('—'),
            TextEntry::make('coach.name')->label('Відповідальний тренер'),
            TextEntry::make('status')->label('Статус супроводу')->badge()
                ->formatStateUsing(fn (string $state): string => Client::STATUSES[$state]),
            TextEntry::make('coaching_started_at')->label('Початок супроводу (UTC)')->dateTime()->timezone('UTC')->placeholder('Ще не розпочато'),
            TextEntry::make('created_at')->label('Створено (UTC)')->dateTime()->timezone('UTC'),
            TextEntry::make('updated_at')->label('Оновлено (UTC)')->dateTime()->timezone('UTC'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('ID картки')->searchable()->sortable(),
            TextColumn::make('user.name')->label('Клієнт')->searchable()->sortable()->placeholder('Без акаунта'),
            TextColumn::make('user.email')->label('Email')->searchable()->placeholder('—'),
            TextColumn::make('coach.name')->label('Тренер')->searchable()->sortable(),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => Client::STATUSES[$state]),
            TextColumn::make('coaching_started_at')->label('Початок (UTC)')->dateTime()->timezone('UTC')->sortable()->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->label('Статус супроводу')->options(Client::STATUSES),
        ])->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('id', 'desc');
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
        return $user->name.' (#'.$user->id.')'.($user->email ? ' — '.$user->email : '');
    }
}
