<?php

namespace App\Filament\Resources\ArticleTasks\Pages;

use App\Filament\Resources\ArticleTasks\ArticleTaskResource;
use Filament\Resources\Pages\ListRecords;

class ListArticleTasks extends ListRecords
{
    protected static string $resource = ArticleTaskResource::class;
}
