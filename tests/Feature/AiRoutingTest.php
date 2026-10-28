<?php

namespace Tests\Feature;

use App\Enums\PromptType;
use App\Filament\Pages\AiRouting;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\AiManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class AiRoutingTest extends TestCase
{
    use RefreshDatabase;

    private AiProvider $openai;

    private AiProvider $deepseek;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->openai = AiProvider::create(['name' => 'OpenAI', 'driver' => 'openai', 'base_url' => 'https://openai.test/v1', 'api_key' => 'o', 'model' => 'gpt-5', 'image_model' => 'gpt-image-1', 'supports_vision' => true, 'is_default' => true, 'is_active' => true]);
        $this->deepseek = AiProvider::create(['name' => 'DeepSeek', 'driver' => 'openai', 'base_url' => 'https://deepseek.test/v1', 'api_key' => 'd', 'model' => 'deepseek-chat', 'supports_vision' => false, 'is_active' => true]);
    }

    private function fakeChat(array $failing = []): void
    {
        Http::fake(function (Request $r) use ($failing) {
            $host = parse_url($r->url(), PHP_URL_HOST);
            if (in_array($host, $failing, true)) {
                return Http::response(['error' => 'down'], 500);
            }
            if (str_ends_with($r->url(), '/responses')) {
                return Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"from":"responses"}']]]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]]);
            }

            return Http::response(['choices' => [['message' => ['content' => json_encode(['from' => $host, 'model' => $r['model']])]]], 'usage' => []]);
        });
    }

    private function route(array $routes): void
    {
        Settings::set('ai_routes', $routes);
    }

    public function test_each_purpose_uses_its_own_provider(): void
    {
        $this->fakeChat();
        $this->route([
            'site' => ['primary' => $this->openai->id, 'primary_model' => 'gpt-5.1'],
            'article' => ['primary' => $this->deepseek->id],
        ]);
        $ai = app(AiManager::class);

        $article = $ai->prompt(PromptType::ArticlePolish, ['brand' => 'b', 'html' => '<p>x</p>', 'keyword' => 'k'])->json();
        $site = $ai->prompt(PromptType::SiteMeta, ['brand' => 'b', 'keywords' => 'k', 'facts' => ''])->json();

        $this->assertSame(['from' => 'deepseek.test', 'model' => 'deepseek-chat'], $article);
        $this->assertSame(['from' => 'openai.test', 'model' => 'gpt-5.1'], $site, 'the model override is sent');
        $this->assertSame('gpt-5', $this->openai->fresh()->model, 'the override is not saved on the provider');
    }

    public function test_backup_provider_takes_over_when_the_main_one_fails(): void
    {
        $this->fakeChat(failing: ['deepseek.test']);
        $this->route(['article' => ['primary' => $this->deepseek->id, 'fallback' => $this->openai->id, 'fallback_model' => 'gpt-5-mini']]);

        $res = app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'article_generate', json: true)->json();

        $this->assertSame(['from' => 'openai.test', 'model' => 'gpt-5-mini'], $res);
        $this->assertDatabaseHas('ai_usage_logs', ['ai_provider_id' => $this->deepseek->id, 'success' => false]);
        $this->assertDatabaseHas('ai_usage_logs', ['ai_provider_id' => $this->openai->id, 'success' => true]);
    }

    public function test_screenshots_go_to_a_provider_that_can_read_them(): void
    {
        $this->fakeChat();
        $this->route(['site' => ['primary' => $this->deepseek->id]]);

        $res = app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'design_config', images: ['data:image/png;base64,AA'], json: true)->json();

        $this->assertSame('openai.test', $res['from']);
    }

    public function test_nothing_assigned_uses_the_default_provider(): void
    {
        $this->fakeChat();

        $this->assertSame('openai.test', app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'article_generate', json: true)->json()['from']);
    }

    public function test_codex_models_use_the_responses_api(): void
    {
        $this->fakeChat();
        $this->route(['site' => ['primary' => $this->openai->id, 'primary_model' => 'gpt-5-codex']]);

        $res = app(AiManager::class)->chat([['role' => 'system', 'content' => 'be brief'], ['role' => 'user', 'content' => 'hi']], 'site_data', json: true);

        $this->assertSame(['from' => 'responses'], $res->json());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/responses') && $r['model'] === 'gpt-5-codex'
            && $r['instructions'] === 'be brief' && $r['input'][0]['content'][0]['text'] === 'hi');
    }

    public function test_images_use_the_image_route_and_its_backup(): void
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        $other = AiProvider::create(['name' => 'Img2', 'driver' => 'openai', 'base_url' => 'https://img2.test/v1', 'api_key' => 'x', 'model' => 'm', 'image_model' => 'flux', 'is_active' => true]);
        Http::fake([
            'openai.test/*' => Http::response(['error' => 'busy'], 500),
            'img2.test/*' => Http::response(['data' => [['b64_json' => base64_encode($png)]]]),
        ]);
        $this->route(['image' => ['primary' => $this->openai->id, 'primary_model' => 'gpt-image-1', 'fallback' => $other->id, 'fallback_model' => 'flux-2']]);

        $this->assertSame($png, app(AiManager::class)->image('a cat'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'img2.test') && $r['model'] === 'flux-2');

        // The image model is set on the assignment only.
        $this->route(['image' => ['primary' => $other->id]]);
        $this->assertNull(app(AiManager::class)->image('a cat'));
    }

    public function test_routing_page_saves_the_assignment(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->get('https://admin.test/admin/ai-routing')->assertOk()->assertSee('网站生成')->assertSee('查重向量');

        Livewire::test(AiRouting::class)
            ->set('data.routes.article.primary', (string) $this->deepseek->id)
            ->set('data.routes.site.primary', (string) $this->openai->id)
            ->set('data.routes.site.primary_model', ' gpt-5-codex ')
            ->call('save');

        $routes = Settings::get('ai_routes');
        $this->assertSame($this->deepseek->id, $routes['article']['primary']);
        $this->assertSame('gpt-5-codex', $routes['site']['primary_model']);
        $this->assertNull($routes['image']['primary']);
    }

    public function test_upgrade_assigns_the_current_providers(): void
    {
        Settings::set('ai_routes', []);
        (require database_path('migrations/2026_10_28_000026_assign_ai_providers_by_purpose.php'))->up();

        $routes = Settings::get('ai_routes');
        $this->assertSame([$this->openai->id, $this->deepseek->id], [$routes['site']['primary'], $routes['article']['fallback']]);
        $this->assertSame($this->openai->id, $routes['image']['primary']);
        $this->assertNull($routes['site']['fallback'], 'DeepSeek cannot read screenshots');
        $this->assertNull($routes['embedding']['primary']);
    }

    public function test_upgrade_moves_image_models_to_the_assignment(): void
    {
        $this->route(['image' => ['primary' => $this->openai->id], 'embedding' => ['primary' => $this->deepseek->id]]);
        (require database_path('migrations/2026_10_28_000028_move_image_and_embedding_models_to_routes.php'))->up();

        $routes = Settings::get('ai_routes');
        $this->assertSame('gpt-image-1', $routes['image']['primary_model']);
        $this->assertNull($routes['embedding']['primary_model']);
    }
}
