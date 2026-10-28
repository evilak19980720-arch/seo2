<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Services\Content\PublishScheduler;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /** Manual edits go live through the same incremental rebuild as publishing. */
    protected function afterSave(): void
    {
        if ($this->record->status->isRendered() && $this->record->site->built_at) {
            app(PublishScheduler::class)->afterChange($this->record);
        }
    }
}
