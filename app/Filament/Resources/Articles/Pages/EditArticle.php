<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ArticleResource::withPublishDate($data);
    }

    /** Manual edits go live through the same incremental rebuild as publishing; so does taking an article down. */
    protected function afterSave(): void
    {
        ArticleResource::syncSite($this->record, statusChanged: $this->record->wasChanged('status'));
    }
}
