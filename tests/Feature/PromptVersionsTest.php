<?php

namespace Tests\Feature;

use App\Enums\PromptType;
use App\Filament\Resources\Prompts\PromptResource;
use App\Models\Prompt;
use App\Models\User;
use Database\Seeders\PromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptVersionsTest extends TestCase
{
    use RefreshDatabase;

    private function oldVersion(): Prompt
    {
        return Prompt::create(['type' => PromptType::ArticleGenerate, 'version' => 1, 'system' => 's', 'template' => 'my old wording {{keyword}}', 'is_active' => false]);
    }

    public function test_latest_tab_shows_newest_versions_and_history_the_rest(): void
    {
        $v2 = Prompt::where(['type' => PromptType::ArticleGenerate->value, 'version' => PromptSeeder::VERSIONS[PromptType::ArticleGenerate->value]])->sole();
        $v1 = $this->oldVersion();
        $pt = Prompt::create(['type' => PromptType::ArticleGenerate, 'locale' => 'pt-BR', 'version' => 1, 'system' => 's', 'template' => 't', 'is_active' => true]);

        $latest = Prompt::latestVersions()->pluck('id');
        $history = Prompt::latestVersions(history: true)->pluck('id');

        $this->assertTrue($latest->contains($v2->id));
        $this->assertTrue($latest->contains($pt->id), 'a locale-specific prompt is its own group');
        $this->assertFalse($latest->contains($v1->id));
        $this->assertSame([$v1->id], $history->intersect([$v1->id, $v2->id, $pt->id])->values()->all());
        $this->assertSame(Prompt::count(), $latest->count() + $history->count());
    }

    public function test_restoring_an_old_version_makes_it_the_one_in_use(): void
    {
        $v1 = $this->oldVersion();

        $restored = PromptResource::copyAsLatest($v1);

        $this->assertSame(PromptSeeder::VERSIONS[PromptType::ArticleGenerate->value] + 1, $restored->version);
        $this->assertSame($restored->id, Prompt::resolve(PromptType::ArticleGenerate)->id);
        $this->assertSame(1, Prompt::where('type', PromptType::ArticleGenerate->value)->where('is_active', true)->count());
    }

    public function test_prompt_list_tabs_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->get('https://admin.test/admin/prompts')->assertOk()->assertSee('最新版本')->assertSee('历史版本');
        $this->get('https://admin.test/admin/prompts?tab=history')->assertOk();
    }
}
