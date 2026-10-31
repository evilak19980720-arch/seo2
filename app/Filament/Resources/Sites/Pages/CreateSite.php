<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Services\Sites\SiteManager;
use Filament\Resources\Pages\CreateRecord;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function afterCreate(): void
    {
        $site = $this->record;
        $state = $this->form->getRawState();

        $site->brandProfile()->firstOrCreate([], ['product_name' => $site->product_name]);

        if ($state['generate_now'] ?? false) {
            app(SiteManager::class)->generate($site);
        }
    }
}
