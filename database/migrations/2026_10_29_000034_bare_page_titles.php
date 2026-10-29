<?php

use App\Models\Page;
use App\Services\Content\SeoRules;
use Illuminate\Database\Migrations\Migration;

/** Column names and page titles lose the brand or site name that used to be joined onto them. */
return new class extends Migration
{
    public function up(): void
    {
        Page::with('site')->where('type', '!=', 'home')->each(function (Page $page) {
            if (! $page->site) {
                return;
            }
            $names = [$page->site->product_name, $page->site->name];
            $title = SeoRules::bareTitle((string) $page->title, $names);
            $meta = $page->meta_title;
            if ($meta !== null && SeoRules::bareTitle($meta, $names) !== trim(preg_replace('/\s+/u', ' ', $meta))) {
                $meta = null;
            }
            if ($title !== $page->title || $meta !== $page->meta_title) {
                $page->forceFill(['title' => $title, 'meta_title' => $meta])->saveQuietly();
            }
        });
    }

    public function down(): void {}
};
