<?php

use App\Models\AiProvider;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;

/**
 * Start AI 用途分配 from what the platform did before: the default provider
 * for site and article work (a vision provider for the site when the default
 * cannot read screenshots), the providers with an image / embedding model for
 * pictures and dedup, and the next active provider as the backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (filled(Settings::get('ai_routes'))) {
            return;
        }
        $active = AiProvider::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();
        if ($active->isEmpty()) {
            return;
        }
        $pick = fn (callable $can) => $active->filter($can)->values();
        $route = fn ($list) => ['primary' => $list[0]->id ?? null, 'primary_model' => null, 'fallback' => $list[1]->id ?? null, 'fallback_model' => null];

        Settings::set('ai_routes', [
            'site' => $route($pick(fn ($p) => $p->supports_vision)->whenEmpty(fn () => $active)),
            'article' => $route($active),
            'image' => $route($pick(fn ($p) => filled($p->image_model) && $p->driver !== 'anthropic')),
            'embedding' => $route($pick(fn ($p) => filled($p->embedding_model) && ! in_array($p->driver, ['anthropic', 'azure'], true))),
        ]);
    }

    public function down(): void
    {
        Settings::set('ai_routes', []);
    }
};
