<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\EditableNavigation;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** Admin accounts: who can sign in to the back office, and with which role. */
class UserResource extends Resource
{
    use EditableNavigation;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $modelLabel = '系统用户';

    protected static ?string $pluralModelLabel = '系统用户';

    public const ROLES = [User::ROLE_ADMIN => '管理员', User::ROLE_EDITOR => '编辑'];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('姓名')->required()->maxLength(100),
            TextInput::make('email')->label('登录邮箱')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->label('角色')->options(self::ROLES)->default(User::ROLE_EDITOR)->required()
                ->helperText('管理员可以使用全部功能；编辑不能进入全局配置、AI 服务商、AI 用途分配、广告、菜单管理和系统用户'),
            TextInput::make('password')->label('密码')->password()->revealable()->minLength(8)
                ->required(fn (?User $record) => $record === null)
                ->dehydrated(fn ($state) => filled($state))
                ->helperText(fn (?User $record) => $record ? '留空则不修改' : '至少 8 位'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('姓名')->searchable(),
                TextColumn::make('email')->label('登录邮箱')->searchable(),
                TextColumn::make('role')->label('角色')->badge()->formatStateUsing(fn ($state) => self::ROLES[$state] ?? $state)
                    ->color(fn ($state) => $state === User::ROLE_ADMIN ? 'warning' : 'gray'),
                IconColumn::make('mfa')->label('两步验证')->boolean()->state(fn (User $r) => filled($r->app_authentication_secret)),
                TextColumn::make('created_at')->label('创建时间')->dateTime(),
            ])
            ->recordActions([
                EditAction::make()->using(function (User $record, array $data) {
                    self::guardLastAdmin($record, $data['role'] ?? $record->role);
                    $record->update($data);

                    return $record;
                }),
                Action::make('resetMfa')->label('重置两步验证')->icon(Heroicon::OutlinedKey)->color('gray')
                    ->visible(fn (User $r) => filled($r->app_authentication_secret))
                    ->requiresConfirmation()->modalDescription('清除这个账号的两步验证，下次登录后可重新绑定。')
                    ->action(function (User $record) {
                        $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                        Notification::make()->success()->title('已重置两步验证')->send();
                    }),
                DeleteAction::make()
                    ->hidden(fn (User $r) => $r->is(auth()->user()))
                    ->before(function (User $record, DeleteAction $action) {
                        if ($record->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
                            Notification::make()->danger()->title('至少要保留一个管理员')->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    /** The last admin cannot lose the admin role, and nobody demotes themselves. */
    public static function guardLastAdmin(User $user, string $role): void
    {
        if (! $user->isAdmin() || $role === User::ROLE_ADMIN) {
            return;
        }
        if ($user->is(auth()->user())) {
            throw ValidationException::withMessages(['mountedActions.0.data.role' => '不能取消自己的管理员身份']);
        }
        if (User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            throw ValidationException::withMessages(['mountedActions.0.data.role' => '至少要保留一个管理员']);
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
