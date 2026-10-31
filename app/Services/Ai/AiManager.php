<?php

namespace App\Services\Ai;

use App\Enums\AiPurpose;
use App\Enums\PromptType;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Alert;
use App\Models\Prompt;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
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
            'language' => $site->languageName(),
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

        return new AiResponse($response->text, $response->inputTokens, $response->outputTokens, $prompt->id, $response->truncated);
    }

    /**
     * Send the messages to the provider assigned to this call's purpose
     * (AI 用途分配), switching to the backup provider when the first fails.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    public function chat(array $messages, string $purpose, ?Site $site = null, array $images = [], bool $json = false): AiResponse
    {
        $candidates = $this->candidates(AiPurpose::forCall($purpose), vision: $images !== []);
        $last = null;
        foreach ($candidates as $provider) {
            try {
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
            } catch (AiException $e) {
                $last = $e;
            }
        }

        throw $last ?? new AiException($images !== [] ? 'No active AI provider with vision support within budget.' : 'No active AI provider within budget.');
    }

    /**
     * Generate one image (OpenAI-compatible /images/generations), as binary,
     * or null when no provider has an image model or every one fails.
     */
    public function image(string $prompt, ?Site $site = null, string $size = '1536x1024'): ?string
    {
        foreach ($this->candidates(AiPurpose::Image) as $provider) {
            try {
                $payload = ['model' => $provider->image_model, 'prompt' => mb_substr($prompt, 0, 3000), 'n' => 1, 'size' => $provider->options['image_size'] ?? $size];
                $res = $this->http($provider)->timeout(240)->post('/images/generations', $payload);
                // Providers differ in the sizes they accept: fall back to their default.
                if ($res->status() === 400) {
                    unset($payload['size']);
                    $res = $this->http($provider)->timeout(240)->post('/images/generations', $payload);
                }
                $data = $res->throw()->json('data.0') ?? [];
                $binary = isset($data['b64_json']) ? base64_decode((string) $data['b64_json'], true) : (isset($data['url']) ? Http::timeout(60)->get($data['url'])->throw()->body() : null);
                if (! is_string($binary) || $binary === '' || ! @getimagesizefromstring($binary)) {
                    throw new AiException('The image API returned no picture.');
                }
                $this->log($provider, $site, 'image', 0, 0);

                return $binary;
            } catch (Throwable $e) {
                $this->log($provider, $site, 'image', 0, 0, $e->getMessage());
            }
        }

        return null;
    }

    /** Embedding vector for dedup, or null when no provider has an embedding model. */
    public function embed(string $text, ?Site $site = null): ?array
    {
        foreach ($this->candidates(AiPurpose::Embedding) as $provider) {
            try {
                $res = $this->http($provider)->post('/embeddings', [
                    'model' => $provider->embedding_model,
                    'input' => mb_substr($text, 0, 8000),
                ])->throw()->json();
                $this->log($provider, $site, 'embedding', (int) ($res['usage']['prompt_tokens'] ?? 0), 0);
                if ($vector = $res['data'][0]['embedding'] ?? null) {
                    return $vector;
                }
            } catch (Throwable) {
                // Try the backup provider.
            }
        }

        return null;
    }

    /**
     * Providers to try for a purpose, in order: the assigned main and backup
     * provider (with their models), or, while nothing is assigned,
     * every active provider with the default first. Providers that cannot do
     * the job (no vision, no image / embedding model) or are over budget are
     * left out.
     *
     * @return Collection<int, AiProvider>
     */
    public function candidates(AiPurpose $purpose, bool $vision = false): Collection
    {
        $field = $purpose->modelField();
        $route = (array) (Settings::get('ai_routes', [])[$purpose->value] ?? []);
        $assigned = collect([['primary', 'primary_model'], ['fallback', 'fallback_model']])
            ->map(function ($keys) use ($route, $field) {
                $provider = filled($route[$keys[0]] ?? null) ? AiProvider::where('is_active', true)->find($route[$keys[0]]) : null;
                // Chat models default to the provider's own; image and embedding models are set here only.
                if ($provider && (filled($route[$keys[1]] ?? null) || $field !== 'model')) {
                    $provider->{$field} = trim((string) ($route[$keys[1]] ?? ''));
                }

                return $provider;
            })
            ->filter()->unique(fn ($p) => $p->id.'|'.$p->{$field});

        $usable = fn (AiProvider $p) => filled($p->{$field})
            && ! ($vision && ! $p->supports_vision)
            && ! (in_array($purpose, [AiPurpose::Image, AiPurpose::Embedding], true) && $p->driver === 'anthropic')
            && ! ($purpose === AiPurpose::Embedding && $p->driver === 'azure');

        $list = $assigned->filter($usable);
        // Nothing assigned yet, or the assigned ones cannot read screenshots: any capable provider.
        if ($list->isEmpty() && ($assigned->isEmpty() || $vision)) {
            $list = AiProvider::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get()->filter($usable);
        }

        return $list->filter(function (AiProvider $p) {
            if (! $p->overBudget()) {
                return true;
            }
            Alert::raise('ai_budget', "AI 服务商 {$p->name} 本月预算已用完", null, 'danger');

            return false;
        })->values();
    }

    public function test(AiProvider $provider): string
    {
        try {
            $res = $this->send($provider, [['role' => 'user', 'content' => 'Reply with OK']], [], false);
            $result = 'OK: '.mb_substr($res->text, 0, 50);
        } catch (Throwable $e) {
            $result = 'FAIL: '.trim($e->getMessage());
        }
        if ($provider->exists) {
            $provider->update(['last_tested_at' => now(), 'last_test_result' => mb_substr($result, 0, 255)]);
        }

        return $result;
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
        // Codex and other newer OpenAI models only answer on the Responses API.
        if (($provider->options['api'] ?? null) === 'responses' || str_contains(strtolower((string) $provider->model), 'codex')) {
            return $this->callResponses($provider, $messages, $images, $json);
        }
        $original = $messages;
        if ($images !== []) {
            $last = array_pop($messages);
            $content = [['type' => 'text', 'text' => $last['content']]];
            foreach ($images as $url) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
            }
            $messages[] = ['role' => $last['role'], 'content' => $content];
        }

        // Long answers (design config with CSS) must not stop at a provider's small default limit.
        $payload = ['model' => $provider->model, 'messages' => $messages, 'max_tokens' => 16000];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->http($provider)->post('/chat/completions', $payload);
        if (in_array($response->status(), [400, 404], true) && str_contains(strtolower($response->body()), 'v1/responses')) {
            return $this->callResponses($provider, $original, $images, $json);
        }
        // Not every compatible provider accepts response_format or that many tokens; the prompts already ask for JSON.
        if ($response->status() === 400) {
            unset($payload['response_format'], $payload['max_tokens']);
            $response = $this->http($provider)->post('/chat/completions', $payload);
        }
        $res = $response->throw()->json();

        return new AiResponse(
            (string) ($res['choices'][0]['message']['content'] ?? ''),
            (int) ($res['usage']['prompt_tokens'] ?? 0),
            (int) ($res['usage']['completion_tokens'] ?? 0),
            truncated: ($res['choices'][0]['finish_reason'] ?? null) === 'length',
        );
    }

    /** OpenAI Responses API (/responses): instructions + input, text output items. */
    protected function callResponses(AiProvider $provider, array $messages, array $images, bool $json): AiResponse
    {
        $instructions = collect($messages)->where('role', 'system')->pluck('content')->implode("\n\n");
        $input = collect($messages)->where('role', '!=', 'system')->values()->map(fn ($m) => ['role' => $m['role'], 'content' => [['type' => $m['role'] === 'assistant' ? 'output_text' : 'input_text', 'text' => $m['content']]]])->all();
        if ($images !== [] && $input !== []) {
            foreach ($images as $url) {
                $input[count($input) - 1]['content'][] = ['type' => 'input_image', 'image_url' => $url];
            }
        }
        $payload = array_filter([
            'model' => $provider->model,
            'instructions' => $instructions ?: null,
            'input' => $input,
            'max_output_tokens' => 16000,
            'text' => $json ? ['format' => ['type' => 'json_object']] : null,
        ]);

        $response = $this->http($provider)->timeout(300)->post('/responses', $payload);
        if ($response->status() === 400) {
            unset($payload['text'], $payload['max_output_tokens']);
            $response = $this->http($provider)->timeout(300)->post('/responses', $payload);
        }
        $res = $response->throw()->json();
        $text = collect($res['output'] ?? [])->where('type', 'message')->flatMap(fn ($item) => $item['content'] ?? [])
            ->where('type', 'output_text')->pluck('text')->implode('');

        return new AiResponse(
            $text !== '' ? $text : (string) ($res['output_text'] ?? ''),
            (int) ($res['usage']['input_tokens'] ?? 0),
            (int) ($res['usage']['output_tokens'] ?? 0),
            truncated: ($res['incomplete_details']['reason'] ?? null) === 'max_output_tokens',
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

        $post = fn (int $maxTokens) => Http::baseUrl(preg_replace('#/v1$#', '', rtrim($provider->base_url, '/')))
            ->withHeaders(['x-api-key' => $provider->api_key, 'anthropic-version' => '2023-06-01'])
            ->acceptJson()
            ->timeout(300)
            ->post('/v1/messages', array_filter([
                'model' => $provider->model,
                'max_tokens' => $maxTokens,
                'system' => $system ?: null,
                'messages' => $messages,
            ]));
        $response = $post(16000);
        // Older models allow fewer output tokens.
        if ($response->status() === 400 && str_contains($response->body(), 'max_tokens')) {
            $response = $post(8192);
        }
        $res = $response->throw()->json();

        $text = collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode('');

        return new AiResponse($text, (int) ($res['usage']['input_tokens'] ?? 0), (int) ($res['usage']['output_tokens'] ?? 0), truncated: ($res['stop_reason'] ?? null) === 'max_tokens');
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
