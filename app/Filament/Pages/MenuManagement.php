<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\EditableNavigation;
use App\Support\AdminMenu;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/** Rename the admin menu: item names, their group and order, and group names. */
class MenuManagement extends Page
{
    use EditableNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = '系统';

    protected static ?string $title = '菜单管理';

    protected static ?string $slug = 'menu';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    /**
     * Menu items with their default names: [key => [class, label, group, sort]].
     *
     * @return array<string, array{class: string, label: string, group: ?string, sort: ?int}>
     */
    public static function items(): array
    {
        $panel = Filament::getPanel('admin');
        $items = [];
        foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
            if (! in_array(EditableNavigation::class, class_uses_recursive($class), true) || ! $class::shouldRegisterNavigation()) {
                continue;
            }
            $items[Str::snake(class_basename($class))] = AdminMenu::defaults(fn () => [
                'class' => $class,
                'label' => (string) $class::getNavigationLabel(),
                'group' => is_string($g = $class::getNavigationGroup()) ? $g : null,
                'sort' => $class::getNavigationSort(),
            ]);
        }
        uasort($items, fn ($a, $b) => [array_search($a['group'], AdminMenu::GROUPS) === false ? -1 : array_search($a['group'], AdminMenu::GROUPS), $a['sort'] ?? 0, $a['label']]
            <=> [array_search($b['group'], AdminMenu::GROUPS) === false ? -1 : array_search($b['group'], AdminMenu::GROUPS), $b['sort'] ?? 0, $b['label']]);

        return $items;
    }

    public function mount(): void
    {
        $config = (array) Settings::get('admin_menu', []);
        $items = [];
        foreach (self::items() as $key => $item) {
            $saved = $config['items'][$item['class']] ?? [];
            $items[$key] = ['label' => $saved['label'] ?? null, 'group' => $saved['group'] ?? $item['group'], 'sort' => $saved['sort'] ?? null];
        }
        $this->form->fill(['groups' => $config['groups'] ?? [], 'items' => $items]);
    }

    public function form(Schema $schema): Schema
    {
        $groups = collect(AdminMenu::GROUPS)->mapWithKeys(fn ($g) => [$g => AdminMenu::groupLabel($g)])->all();

        return $schema->statePath('data')->components([
            Section::make('分组名称')->description('留空用默认名称')->columns(4)->schema(
                collect(AdminMenu::GROUPS)->map(fn ($g, $i) => TextInput::make("groups.{$g}")->label($g)->placeholder($g)->maxLength(20))->all()
            ),
            Section::make('菜单项')->description('名称留空用默认名称；排序数字越小越靠前，留空用默认顺序')->schema(
                collect(self::items())->map(fn ($item, $key) => Grid::make(3)->schema([
                    TextInput::make("items.{$key}.label")->label($item['label'])->placeholder($item['label'])->maxLength(30),
                    Select::make("items.{$key}.group")->label('所在分组')->options($groups)->placeholder('不分组')->disabled($item['group'] === null),
                    TextInput::make("items.{$key}.sort")->label('排序')->numeric()->placeholder((string) ($item['sort'] ?? '默认')),
                ]))->values()->all()
            ),
        ]);
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reset')->label('恢复默认')->color('gray')->requiresConfirmation()
                ->modalDescription('所有菜单名称、分组和排序恢复为默认。')
                ->action(function () {
                    Settings::set('admin_menu', []);
                    $this->redirect(self::getUrl());
                }),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $groups = collect($state['groups'] ?? [])->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v, $g) => $v !== '' && $v !== $g)->all();
        $items = [];
        foreach (self::items() as $key => $item) {
            $row = (array) ($state['items'][$key] ?? []);
            $saved = array_filter([
                'label' => ($l = trim((string) ($row['label'] ?? ''))) !== '' && $l !== $item['label'] ? $l : null,
                'group' => filled($row['group'] ?? null) && $row['group'] !== $item['group'] ? $row['group'] : null,
                'sort' => is_numeric($row['sort'] ?? null) ? (int) $row['sort'] : null,
            ], fn ($v) => $v !== null);
            if ($saved !== []) {
                $items[$item['class']] = $saved;
            }
        }
        if (collect($groups)->unique()->count() < count($groups) || collect($groups)->intersect(array_diff(AdminMenu::GROUPS, array_keys($groups)))->isNotEmpty()) {
            Notification::make()->danger()->title('分组名称不能重复')->send();

            return;
        }
        Settings::set('admin_menu', ['groups' => $groups, 'items' => $items]);
        Notification::make()->success()->title('已保存，刷新页面后菜单生效')->send();
        $this->redirect(self::getUrl());
    }
}
