<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\EditableNavigation;
use App\Models\Site;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use EditableNavigation;
    use HasFiltersForm;

    protected static ?string $title = '数据面板';

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('site_id')->label('站点')->placeholder('全部站点')
                ->options(fn () => Site::with('domain')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.$s->domain->name]))
                ->searchable(),
            Select::make('range')->label('时间')->options(['1' => '今日', '7' => '7 日', '30' => '30 日'])->default('7')->selectablePlaceholder(false),
        ])->columns(2);
    }
}
