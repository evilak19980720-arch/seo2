<?php

namespace App\Services\Ai;

use App\Enums\PromptType;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Alert;
use App\Models\Prompt;
use App\Models\Site;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Single entry point for every AI call: provider selection, prompt lookup,
 * concurrency slots, budget enforcement and usage accounting.
 */
class AiManager
{
    /**
     * Run a managed prompt (type × site type × language) and return the response.
     *
     * @param  array<string, mixed>  $vars  template variables
     * @param  array<int, string>  $images  image URLs or data URIs (needs a vision provider)
     */
    public function prompt(PromptType $type, array $vars, ?Site $site = null, array $images = [], bool $json = true): AiResponse
    {
        $prompt = Prompt::resolve($type, $site?->site_type, $site?->locale);
        if (! $prompt) {
            throw new AiException("No active prompt for type [{$type->value}].");
        }

        $vars += $site ? [
            'language' => $site->locale,
            'country' => $site->target_country,
            'product_name' => $site->product_name,
            'site_name' => $site->name,
            'site_type' => $site->typeConfig()['label'] ?? $site->site_type,
        ] : [];

        $messages = [];
        if ($prompt->system) {
            $messages[] = ['role' => 'system', 'content' => (new Prompt(['template' => $prompt->system]))->render($vars)];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt->render($vars)];

        $response = $this->chat($messages, $type->value, $site, $images, $json);

        return new AiResponse($response->text, $response->inputTokens, $response->outputTokens, $prompt->id);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, string $purpose, ?Site $site = null, array $images = [], bool $json = false): AiResponse
    {
        $provider = $this->provider(vision: $images !== []);

        return $this->withSlot($provider, function () use ($provider, $messages, $purpose, $site, $images, $json) {
            try {
                $response = $this->send($provider, $messages, $images, $json);
            } catch (Throwable $e) {
                $this->log($provider, $site, $purpose, 0, 0, $e->getMessage());
                throw $e instanceof AiException ? $e : new AiException($e->getMessage(), 0, $e);
            }

            $this->log($provider, $site, $purpose, $response->inputTokens, $response->outputTokens);

            return $response;
        });
    }

    /** Embedding vector for dedup, or null when the provider has no embedding model. */
    public function embed(string $text, ?Site $site = null): ?array
    {
        $provider = AiProvider::where('is_active', true)->whereNotNull('embedding_model')->orderByDesc('is_default')->first();
        if (! $provider || in_array($provider->driver, ['anthropic', 'azure'], true)) {
            return null;
        }

        try {
            $res = $this->http($provider)->post('/embeddings', [
                'model' => $provider->embedding_model,
                'input' => mb_substr($text, 0, 8000),
            ])->throw()->json();
            $this->log($provider, $site, 'embedding', (int) ($res['usage']['prompt_tokens'] ?? 0), 0);

            return $res['data'][0]['embedding'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    public function test(AiProvider $provider): string
    {
        try {
            $res = $this->send($provider, [['role' => 'user', 'content' => 'Reply with OK']], [], false);
            $result = 'OK: '.mb_substr($res->text, 0, 50);
        } catch (Throwable $e) {
            $result = 'FAIL: '.mb_substr($e->getMessage(), 0, 200);
        }
        if ($provider->exists) {
            $provider->update(['last_tested_at' => now(), 'last_test_result' => $result]);
        }

        return $result;
    }

    public function provider(bool $vision = false): AiProvider
    {
        $candidates = AiProvider::where('is_active', true)
            ->when($vision, fn ($q) => $q->where('supports_vision', true))
            ->orderByDesc('is_default')
            ->get();

        foreach ($candidates as $provider) {
            if (! $provider->overBudget()) {
                return $provider;
            }
            Alert::raise('ai_budget', "AI 服务商 {$provider->name} 本月预算已用完", null, 'danger');
        }

        throw new AiException($vision ? 'No active AI provider with vision support within budget.' : 'No active AI provider within budget.');
    }

    protected function withSlot(AiProvider $provider, callable $callback): mixed
    {
        $slots = max(1, $provider->max_concurrency);
        $deadline = microtime(true) + 120;

        while (true) {
            for ($i = 0; $i < $slots; $i++) {
                $lock = Cache::lock("ai-slot:{$provider->id}:{$i}", 300);
                if ($lock->get()) {
                    try {
                        return $callback();
                    } finally {
                        $lock->release();
                    }
                }
            }
            if (microtime(true) > $deadline) {
                throw new AiException("Timed out waiting for a concurrency slot on {$provider->name}.");
            }
            usleep(500_000);
        }
    }

    protected function send(AiProvider $provider, array $messages, array $images, bool $json): AiResponse
    {
        return match ($provider->driver) {
            'anthropic' => $this->callAnthropic($provider, $messages, $images, $json),
            default => $this->callOpenAi($provider, $messages, $images, $json),
        };
    }

    protected function http(AiProvider $provider): PendingRequest
    {
        $request = Http::baseUrl(rtrim($provider->base_url, '/'))->acceptJson()->timeout(180)->retry(2, 2000, throw: false);

        if ($provider->driver === 'azure') {
            return $request->withHeaders(['api-key' => (string) $provider->api_key])
                ->withQueryParameters(['api-version' => $provider->options['api_version'] ?? '2024-10-21']);
        }

        return filled($provider->api_key) ? $request->withToken($provider->api_key) : $request;
    }

    protected function callOpenAi(AiProvider $provider, array $messages, array $images, bool $json): AiResponse
    {
        if ($images !== []) {
            $last = array_pop($messages);
            $content = [['type' => 'text', 'text' => $last['content']]];
            foreach ($images as $url) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            $messages[] = ['role' => $last['role'], 'content' => $content];
        }

        $payload = ['model' => $provider->model, 'messages' => $messages];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->http($provider)->post('/chat/completions', $payload);
        // Not every compatible provider accepts response_format; the prompts already ask for JSON.
        if ($json && $response->status() === 400) {
            unset($payload['response_format']);
            $response = $this->http($provider)->post('/chat/completions', $payload);
        }
        $res = $response->throw()->json();

        return new AiResponse(
            (string) ($res['choices'][0]['message']['content'] ?? ''),
            (int) ($res['usage']['prompt_tokens'] ?? 0),
            (int) ($res['usage']['completion_tokens'] ?? 0),
        );
    }

    protected function callAnthropic(AiProvider $provider, array $messages, array $images, bool $json): AiResponse
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode("\n\n");
        $messages = collect($messages)->where('role', '!=', 'system')->values()->all();

        if ($images !== []) {
            $last = array_pop($messages);
            $content = [];
            foreach ($images as $url) {
                $content[] = str_starts_with($url, 'data:')
                    ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => substr($url, 5, strpos($url, ';') - 5), 'data' => substr($url, strpos($url, ',') + 1)]]
                    : ['type' => 'image', 'source' => ['type' => 'url', 'url' => $url]];
            }
            $content[] = ['type' => 'text', 'text' => $last['content']];
            $messages[] = ['role' => 'user', 'content' => $content];
        }

        if ($json) {
            $system = trim($system."\n\nRespond with a single valid JSON object only.");
        }

        $res = Http::baseUrl(preg_replace('#/v1$#', '', rtrim($provider->base_url, '/')))
            ->withHeaders(['x-api-key' => $provider->api_key, 'anthropic-version' => '2023-06-01'])
            ->acceptJson()
            ->timeout(180)
            ->post('/v1/messages', array_filter([
                'model' => $provider->model,
                'max_tokens' => 8192,
                'system' => $system ?: null,
                'messages' => $messages,
            ]))->throw()->json();

        $text = collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');

        return new AiResponse($text, (int) ($res['usage']['input_tokens'] ?? 0), (int) ($res['usage']['output_tokens'] ?? 0));
    }

    protected function log(AiProvider $provider, ?Site $site, string $purpose, int $in, int $out, ?string $error = null): void
    {
        $cost = ($in * (float) $provider->input_price_per_million + $out * (float) $provider->output_price_per_million) / 1_000_000;

        AiUsageLog::create([
            'ai_provider_id' => $provider->id,
            'site_id' => $site?->id,
            'purpose' => $purpose,
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost' => $cost,
            'success' => $error === null,
            'error' => $error ? mb_substr($error, 0, 250) : null,
        ]);
    }
}
