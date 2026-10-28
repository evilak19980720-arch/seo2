<?php

namespace App\Filament\Resources\Ads\Pages;

use App\Filament\Resources\Ads\AdResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAd extends EditRecord
{
    protected static string $resource = AdResource::class;

    protected array $previousSites = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn () => $this->previousSites = $this->record->sites()->pluck('sites.id')->all())
                ->after(fn () => AdResource::rebuildSites($this->record, $this->previousSites)),
        ];
    }

    protected function beforeSave(): void
    {
        $this->previousSites = $this->record->sites()->pluck('sites.id')->all();
    }

    protected function afterSave(): void
    {
        $now = $this->record->sites()->pluck('sites.id')->all();
        if ($now !== $this->previousSites) {
            $this->record->logChange('sites', ['before' => $this->previousSites, 'after' => $now]);
        }
        AdResource::rebuildSites($this->record, $this->previousSites);
    }
}
