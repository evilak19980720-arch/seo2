<?php

use App\Models\Page;
use App\Services\Content\SeoRules;
use Database\Seeders\PromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/** Column names lose the brand inside them ("Games at Brand" → "Games"); the page prompt asks for none. */
return new class extends Migration
{
    public function up(): void
    {
        (new PromptSeeder)->run();

        Page::with('site')->each(function (Page $page) {
            if (! $page->site) {
                return;
            }
            $bare = SeoRules::bareTitle((string) $page->title, [$page->site->product_name, $page->site->name]);
            if ($bare === '') {
                $lang = $page->site->language();
                $bare = Lang::has("theme.page_titles.{$page->type}", $lang) ? __("theme.page_titles.{$page->type}", [], $lang) : Str::headline($page->type);
            }
            if ($bare === $page->title) {
                return;
            }
            $meta = $page->type !== 'home' && $page->meta_title === $page->title ? $bare : $page->meta_title;
            $page->forceFill(['title' => $bare, 'meta_title' => $meta])->saveQuietly();
        });
    }

    public function down(): void {}
};
