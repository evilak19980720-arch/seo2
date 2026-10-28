<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\Domain;
use App\Services\Sites\SiteManager;
use Filament\Resources\Pages\CreateRecord;

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    protected function afterCreate(): void
    {
        $site = $this->record;
        $state = $this->form->getRawState();

        Domain::whereKey($site->domain_id)->update(['status' => 'used']);
        $site->brandProfile()->firstOrCreate([], ['product_name' => $site->product_name]);
        app(SiteManager::class)->syncRelations($site, $state['related_site_ids'] ?? []);

        if ($state['generate_now'] ?? false) {
            app(SiteManager::class)->generate($site);
        }
    }
}
