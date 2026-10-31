<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ArticleResource::withPublishDate($data);
    }

    /** An article created as published goes live at once, like a published AI article. */
    protected function afterCreate(): void
    {
        ArticleResource::syncSite($this->record, statusChanged: false);
    }
}
