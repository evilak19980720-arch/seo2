<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Services\Ai\AiManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function provider(array $attributes): AiProvider
    {
        return AiProvider::create($attributes + ['name' => 'p', 'model' => 'm', 'is_default' => true, 'is_active' => true]);
    }

    public function test_every_preset_is_complete(): void
    {
        foreach (AiProvider::presets() as $key => $preset) {
            $this->assertNotEmpty($preset['label'], $key);
            $this->assertContains($preset['driver'], ['openai', 'anthropic', 'azure'], $key);
            $this->assertArrayHasKey('base_url', $preset, $key);
            $this->assertIsArray($preset['models'], $key);
        }
        $this->assertGreaterThanOrEqual(20, count(AiProvider::presets()));
    }

    public function test_azure_uses_api_key_header_and_api_version(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]], 'usage' => []])]);
        $this->provider(['driver' => 'azure', 'base_url' => 'https://r.openai.azure.com/openai/deployments/gpt', 'api_key' => 'az', 'options' => ['api_version' => '2025-01-01']]);

        app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'test');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://r.openai.azure.com/openai/deployments/gpt/chat/completions?api-version=2025-01-01'
            && $r->header('api-key') === ['az'] && ! $r->hasHeader('Authorization'));
    }

    public function test_local_provider_without_key_sends_no_authorization(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]], 'usage' => []])]);
        $this->provider(['preset' => 'ollama', 'driver' => 'openai', 'base_url' => 'http://127.0.0.1:11434/v1', 'api_key' => null]);

        app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'test');

        Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
    }

    public function test_json_mode_falls_back_when_provider_rejects_response_format(): void
    {
        Http::fake(function (Request $r) {
            return isset($r['response_format'])
                ? Http::response(['error' => ['message' => 'response_format not supported']], 400)
                : Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => []]);
        });
        $this->provider(['driver' => 'openai', 'base_url' => 'https://ai.test/v1', 'api_key' => 'k']);

        $res = app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'test', json: true);

        $this->assertSame(['ok' => true], $res->json());
    }

    public function test_anthropic_base_url_with_v1_suffix_is_accepted(): void
    {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'OK']], 'usage' => []])]);
        $this->provider(['driver' => 'anthropic', 'base_url' => 'https://api.anthropic.com/v1', 'api_key' => 'k']);

        app(AiManager::class)->chat([['role' => 'user', 'content' => 'hi']], 'test');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages');
    }
}
