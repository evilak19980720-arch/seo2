<?php

namespace App\Filament\Resources\Prompts\Pages;

use App\Filament\Resources\Prompts\PromptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPrompts extends ListRecords
{
    protected static string $resource = PromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'latest' => Tab::make('最新版本')->modifyQueryUsing(fn (Builder $query) => $query->latestVersions()),
            'history' => Tab::make('历史版本')->modifyQueryUsing(fn (Builder $query) => $query->latestVersions(history: true)),
        ];
    }
}
