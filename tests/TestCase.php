<?php

namespace Tests;

use App\Models\AiProvider;
use App\Models\Domain;
use App\Models\Site;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected bool $seed = true;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/sn-test-'.uniqid();
        File::ensureDirectoryExists($this->tmp);
        touch($this->tmp.'/installed.lock');
        config([
            'platform.installed_lock' => $this->tmp.'/installed.lock',
            'platform.output_path' => $this->tmp.'/sites',
            'platform.certs_path' => $this->tmp.'/certs',
            'platform.admin_domain' => 'admin.test',
            'platform.lighthouse.bin' => '',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    protected function makeSite(array $attrs = [], array $brand = []): Site
    {
        $domain = Domain::create(['name' => $attrs['domain'] ?? 'jogo-'.uniqid().'.com.br', 'cf_zone_id' => 'zone-'.uniqid(), 'cf_status' => 'active']);
        unset($attrs['domain']);
        $site = Site::create($attrs + [
            'domain_id' => $domain->id, 'site_type' => 'game_basic', 'product_name' => 'Galaxy Run', 'name' => 'Galaxy Run',
            'locale' => 'pt-BR', 'target_country' => 'BR', 'status' => 'draft',
        ]);
        $site->brandProfile()->create($brand + [
            'product_name' => 'Galaxy Run',
            'tagline' => 'Corra entre planetas no jogo de corrida espacial',
            'description' => str_repeat('Galaxy Run é um jogo de corrida espacial para celular em que você desvia de asteroides e coleta estrelas. ', 4),
            'selling_points' => ['Partidas rápidas', 'Gratuito para jogar', 'Modo multijogador online'],
            'specs' => [['key' => 'Plataforma', 'value' => 'Android, iOS'], ['key' => 'Gênero', 'value' => 'Corrida']],
            'media' => [],
            'cta_links' => [['label' => 'Baixar grátis', 'url' => 'https://play.google.com/store/apps/details?id=x']],
            'faq' => [['question' => 'É grátis?', 'answer' => 'Sim.'], ['question' => 'Tem modo online?', 'answer' => 'Sim.'], ['question' => 'Onde jogar?', 'answer' => 'Android e iOS.']],
            'company_name' => 'Acme Games', 'company_email' => 'contato@acme.test',
        ]);

        return $site->fresh(['domain', 'brandProfile']);
    }

    /** Fake an OpenAI-compatible provider that answers each managed prompt type. */
    protected function fakeAi(array $overrides = []): void
    {
        AiProvider::create([
            'name' => 'fake', 'driver' => 'openai', 'base_url' => 'https://ai.test/v1', 'api_key' => 'k', 'model' => 'm',
            'is_default' => true, 'is_active' => true, 'supports_vision' => true,
        ]);

        Http::fake(['ai.test/*' => function ($request) use ($overrides) {
            $text = collect($request['messages'] ?? [])->pluck('content')->flatten()->map(fn ($c) => is_array($c) ? ($c['text'] ?? '') : $c)->implode("\n");
            $answer = match (true) {
                str_contains($text, 'homepage SEO title') => ['title' => 'Galaxy Run: jogo de corrida espacial grátis', 'description' => 'Baixe Galaxy Run e corra entre planetas.'],
                str_contains($text, 'Design the visual identity') => $overrides['design'] ?? ['design' => [
                    'colors' => ['primary' => '#ff0', 'secondary' => '#111111', 'accent' => '#00ffaa', 'background' => '#ffffff', 'surface' => '#f5f5f5', 'text' => '#cccccc', 'muted' => '#dddddd'],
                    'fonts' => ['heading' => 'comic-sans', 'body' => 'inter'],
                    'radius' => 'lg', 'density' => 'normal', 'shadow' => 'soft', 'nav' => 'bar', 'footer' => 'dark',
                    'pages' => ['home' => [['block' => 'hero', 'variant' => 'split'], ['block' => 'nonsense'], ['block' => 'faq', 'variant' => 'zzz']]],
                ], 'custom_css' => '@import url(https://evil.test/x.css);'],
                str_contains($text, 'List the site data') => $overrides['site_data'] ?? [],
                str_contains($text, 'Write the detail pages') => $overrides['entry_content'] ?? ['items' => []],
                str_contains($text, 'Write the core pages') && isset($overrides['pages']) => ['pages' => $overrides['pages']],
                str_contains($text, 'Write the core pages') => ['pages' => [
                    'product' => ['slug' => 'Sobre o Jogo!', 'title' => 'Sobre o jogo', 'meta_title' => 'Sobre Galaxy Run', 'meta_description' => 'Tudo sobre Galaxy Run', 'blocks' => ['hero' => ['heading' => 'Galaxy Run', 'subheading' => 'Corrida espacial']]],
                    'guide' => ['slug' => 'guia/como-jogar', 'title' => 'Como jogar', 'blocks' => ['prose' => ['html' => '<h2>Controles</h2><p>Deslize para desviar.</p><script>x()</script>']]],
                ]],
                str_contains($text, 'Propose') => ['keywords' => [['keyword' => 'Galaxy Run como jogar', 'intent' => 'informational'], ['keyword' => 'galaxy run dicas', 'intent' => 'informational']]],
                str_contains($text, 'Write an original') => $overrides['article'] ?? ['title' => 'Como jogar Galaxy Run: guia completo', 'slug' => 'como-jogar-galaxy-run', 'meta_title' => 'Como jogar Galaxy Run', 'meta_description' => 'Guia para iniciantes.', 'featured_image_alt' => 'Tela do jogo', 'html' => self::portugueseArticle()],
                str_contains($text, 'Polish this article') => ['html' => $overrides['polished'] ?? self::portugueseArticle()],
                str_contains($text, 'Review this article') => ['pass' => true, 'issues' => []],
                str_contains($text, 'List every claim') => $overrides['factcheck'] ?? ['supported' => true, 'unsupported_claims' => []],
                str_contains($text, 'Rewrite this brand profile') => $overrides['brand'] ?? ['tagline' => 'Corra entre planetas'],
                default => ['ok' => true],
            };

            return Http::response(['choices' => [['message' => ['content' => json_encode($answer)]]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200]]);
        }]);
    }

    public static function portugueseArticle(int $paragraphs = 14): string
    {
        $sentences = [
            'Galaxy Run é um jogo de corrida espacial em que você desvia de asteroides e coleta estrelas pelo caminho.',
            'Para começar, baixe o jogo na loja do seu celular e escolha o seu primeiro piloto na tela inicial.',
            'As partidas são rápidas, então você pode jogar em qualquer momento do dia sem perder o progresso.',
            'No modo multijogador online, você corre contra outros jogadores e precisa manter o foco em cada curva.',
            'Uma boa dica é deslizar com calma e não mudar de faixa no último segundo, porque isso causa colisões.',
            'Colete o máximo de estrelas que puder, pois elas ajudam você a avançar mais rápido nas fases seguintes.',
            'Quando a velocidade aumentar, observe o padrão dos obstáculos e antecipe o seu movimento com cuidado.',
        ];
        $html = '';
        for ($i = 0; $i < $paragraphs; $i++) {
            if ($i % 4 === 0) {
                $html .= '<h2>Parte '.($i / 4 + 1).' do guia</h2>';
            }
            $html .= '<p>'.implode(' ', array_map(fn ($k) => $sentences[($i + $k) % count($sentences)], range(0, 3))).'</p>';
        }

        return $html;
    }
}
