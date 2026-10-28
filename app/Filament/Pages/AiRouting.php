<?php

namespace App\Filament\Pages;

use App\Enums\AiPurpose;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Which AI provider (and backup) each kind of work uses. */
class AiRouting extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $title = 'AI 用途分配';

    protected static ?string $slug = 'ai-routing';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['routes' => Settings::get('ai_routes', [])]);
    }

    public function form(Schema $schema): Schema
    {
        $providers = AiProvider::where('is_active', true)->orderBy('name')->get();
        $options = $providers->mapWithKeys(fn (AiProvider $p) => [$p->id => $p->name])->all();
        $spend = $this->monthSpend();

        return $schema->statePath('data')->components(collect(AiPurpose::cases())->map(function (AiPurpose $purpose) use ($providers, $options, $spend) {
            $field = $purpose->modelField();
            $hint = fn (?string $id) => ($p = $providers->firstWhere('id', (int) $id)) && filled($p->{$field}) ? '留空用接口里的 '.$p->{$field} : '留空用接口里的模型';

            return Section::make($purpose->getLabel())
                ->description($purpose->description().'　本月花费 $'.number_format($spend[$purpose->value] ?? 0, 2))
                ->columns(2)
                ->schema([
                    Select::make("routes.{$purpose->value}.primary")->label('主接口')->options($options)->placeholder('未分配（用默认接口）')->live(),
                    TextInput::make("routes.{$purpose->value}.primary_model")->label('模型（可空）')
                        ->placeholder(fn (Get $get) => $hint($get("routes.{$purpose->value}.primary"))),
                    Select::make("routes.{$purpose->value}.fallback")->label('备用接口')->options($options)->placeholder('无')->live()
                        ->helperText('主接口出错、超时或超出月预算时自动改用'),
                    TextInput::make("routes.{$purpose->value}.fallback_model")->label('模型（可空）')
                        ->placeholder(fn (Get $get) => $hint($get("routes.{$purpose->value}.fallback"))),
                ]);
        })->all());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('保存')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $routes = [];
        foreach (AiPurpose::cases() as $purpose) {
            $row = (array) ($this->form->getState()['routes'][$purpose->value] ?? []);
            $routes[$purpose->value] = [
                'primary' => filled($row['primary'] ?? null) ? (int) $row['primary'] : null,
                'primary_model' => trim((string) ($row['primary_model'] ?? '')) ?: null,
                'fallback' => filled($row['fallback'] ?? null) ? (int) $row['fallback'] : null,
                'fallback_model' => trim((string) ($row['fallback_model'] ?? '')) ?: null,
            ];
        }
        Settings::set('ai_routes', $routes);
        Notification::make()->success()->title('已保存')->send();
    }

    /** @return array<string, float> this month's AI cost per purpose */
    protected function monthSpend(): array
    {
        return AiUsageLog::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('purpose, sum(cost) as total')->groupBy('purpose')->pluck('total', 'purpose')
            ->groupBy(fn ($total, $purpose) => AiPurpose::forCall((string) $purpose)->value, preserveKeys: true)
            ->map(fn ($group) => (float) $group->sum())->all();
    }
}
