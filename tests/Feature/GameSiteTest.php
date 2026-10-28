<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\RelationManagers\EntriesRelationManager;
use App\Models\GenerationTask;
use App\Models\Site;
use App\Models\User;
use App\Services\Render\MediaPublisher;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use App\Services\Sites\SiteGenerator;
use App\Services\Sites\SiteStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class GameSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function gameSite(array $attrs = [], array $brand = []): Site
    {
        return $this->makeSite($attrs + ['site_type' => 'game'], $brand);
    }

    /** Pages with one paragraph per prose section, as generation would store them. */
    protected function fillPages(Site $site): void
    {
        foreach ($site->typeConfig()['pages'] as $type => $def) {
            $content = [];
            foreach ($def['sections'] as $section) {
                $content[$section['key']] = match ($section['block']) {
                    'prose', 'legal' => ['heading' => 'Seção '.$section['key'], 'html' => '<p>Texto da seção '.$section['key'].' do Galaxy Run.</p>'],
                    'faq' => ['items' => [['question' => 'Pergunta '.$section['key'].'?', 'answer' => 'Resposta.']]],
                    'hero' => ['heading' => 'Galaxy Run jogos de slots', 'subheading' => 'Bem-vindo'],
                    default => ['heading' => 'Lista '.$section['key']],
                };
            }
            $site->pages()->create([
                'type' => $type, 'slug' => $type === 'home' ? '' : ($def['slug']['pt'] ?? $def['slug']['en']),
                'title' => 'Página '.$type, 'content' => $content,
            ]);
        }
    }

    protected function addEntries(Site $site): void
    {
        $games = [
            ['Fortune Tiger', 'PG Soft', ['hot'], ['Animais', 'Ásia'], ['Wild']],
            ['Gates of Olympus', 'Pragmatic Play', ['hot', 'classic'], ['Mitologia'], ['Tumble', 'Multiplicador']],
            ['Sweet Bonanza', 'Pragmatic Play', ['new'], ['Doces'], ['Tumble']],
            ['Mahjong Ways', 'PG Soft', [], ['Ásia'], ['Ways']],
        ];
        foreach ($games as $i => [$title, $provider, $tags, $themes, $mechanics]) {
            $site->entries()->create([
                'collection' => 'game', 'slug' => Str::slug($title), 'title' => $title, 'sort' => $i,
                'summary' => "{$title} é um slot de {$provider} com tema ".implode(', ', $themes).' e rodadas rápidas para jogar no celular.',
                'data' => ['provider' => $provider, 'tags' => $tags, 'themes' => $themes, 'mechanics' => $mechanics, 'rtp' => '96,5%'],
                'sections' => ['details' => ['heading' => 'Sobre '.$title, 'html' => '<p>Detalhes de '.$title.'.</p>']],
            ]);
        }
        $site->entries()->create(['collection' => 'provider', 'slug' => 'pg-soft', 'title' => 'PG Soft', 'summary' => 'Estúdio de slots para celular.']);
        $site->entries()->create(['collection' => 'promotion', 'slug' => 'bonus-boas-vindas', 'title' => 'Bônus de boas-vindas', 'summary' => 'Oferta para novos jogadores.', 'data' => ['tags' => ['new_user'], 'reward' => 'R$ 20', 'ends_at' => now()->addMonth()->toDateString()]]);
        $site->entries()->create(['collection' => 'promotion', 'slug' => 'antiga', 'title' => 'Promoção antiga', 'data' => ['ends_at' => now()->subMonth()->toDateString()]]);
        $site->entries()->create(['collection' => 'notice', 'slug' => 'manutencao', 'title' => 'Manutenção programada', 'data' => ['tags' => ['maintenance'], 'date' => now()->subDay()->toDateString()]]);
    }

    public function test_structure_is_stable_random_and_respects_conditions(): void
    {
        $type = config('site_types.game');
        $a = $this->gameSite();
        $first = SiteStructure::choose($a, $type);
        $this->assertSame($first, SiteStructure::choose($a, $type), 'same site, same structure');

        foreach (['home', 'games', 'lobby', 'promotions', 'help', 'about', 'responsible', 'terms', 'privacy', 'sitemap'] as $required) {
            $this->assertArrayHasKey($required, $first['pages']);
        }
        // No accounts, no payments, no licence: none of those pages or sections.
        $this->assertArrayNotHasKey('account', $first['pages']);
        $this->assertNotContains('register_login', $first['pages']['guide'] ?? []);
        $this->assertNotContains('payments', $first['pages']['help']);
        $this->assertNotContains('license', $first['pages']['fairness'] ?? []);
        foreach ($first['pages'] as $sections) {
            $this->assertGreaterThanOrEqual(1, count($sections));
        }

        $b = $this->gameSite(['has_accounts' => true, 'has_payments' => true]);
        $withAccounts = SiteStructure::choose($b, $type);
        $this->assertArrayHasKey('account', $withAccounts['pages']);

        // Different sites differ somewhere in their combination.
        $shapes = collect(range(1, 6))->map(fn () => json_encode(SiteStructure::choose($this->gameSite(), $type)))->unique();
        $this->assertGreaterThan(1, $shapes->count());

        // A regenerated structure keeps what the site already had.
        $again = SiteStructure::choose($a, $type, ['pages' => ['events' => ['list', 'rules']]] + $first);
        $this->assertArrayHasKey('events', $again['pages']);
        $this->assertContains('rules', $again['pages']['events']);
    }

    public function test_game_site_renders_sections_lobby_entries_and_sitemap(): void
    {
        $site = $this->gameSite();
        $site->update(['structure' => SiteStructure::choose($site, config('site_types.game'))]);
        $site = $site->fresh(['domain', 'brandProfile']);
        $this->fillPages($site);
        $this->addEntries($site);

        $written = (new StaticSiteBuilder)->buildAll($site);
        $dir = StaticSiteBuilder::dirFor($site);

        $lobbySlug = $site->pages()->where('type', 'lobby')->value('slug');
        $gamesSlug = $site->pages()->where('type', 'games')->value('slug');
        $this->assertContains("{$gamesSlug}/fortune-tiger", $written, 'games own the game detail pages');

        $lobby = file_get_contents("{$dir}/{$lobbySlug}/index.html");
        $this->assertStringContainsString('data-lobby', $lobby);
        $this->assertStringContainsString('data-filter="provider"', $lobby);
        $this->assertStringContainsString('data-tab="favorites"', $lobby);
        $this->assertStringContainsString("/{$gamesSlug}/gates-of-olympus/", $lobby);
        $this->assertSame(1, substr_count($lobby, '<h1'));

        $detail = file_get_contents("{$dir}/{$gamesSlug}/fortune-tiger/index.html");
        $this->assertStringContainsString('<h1>Fortune Tiger</h1>', $detail);
        $this->assertStringContainsString('96,5%', $detail, 'known parameters are shown');
        $this->assertStringContainsString('Mahjong Ways', $detail, 'same provider games are related');
        $this->assertStringContainsString('"@type":"VideoGame"', $detail);
        $this->assertStringNotContainsString('Volatilidade', $detail, 'unknown parameters are not invented');

        $home = file_get_contents("{$dir}/index.html");
        $this->assertSame(1, substr_count($home, '<h1'));
        $this->assertStringContainsString('Fortune Tiger', $home);

        $promos = file_get_contents("{$dir}/".$site->pages()->where('type', 'promotions')->value('slug').'/index.html');
        $this->assertStringContainsString('Bônus de boas-vindas', $promos);
        $this->assertStringContainsString('R$ 20', $promos);

        $map = file_get_contents("{$dir}/".$site->pages()->where('type', 'sitemap')->value('slug').'/index.html');
        $this->assertStringContainsString('Gates of Olympus', $map);

        $xml = file_get_contents("{$dir}/sitemap.xml");
        $this->assertStringContainsString("/{$gamesSlug}/sweet-bonanza/", $xml);

        // Legal pages sit in the footer's legal list, not the page list; main nav is capped.
        $menu = $site->fresh()->design_config ?? (new ThemeContext($site, new MediaPublisher($dir)))->design;
        $this->assertLessThanOrEqual(6, count($menu['menu']));
    }

    public function test_generation_fills_site_data_from_the_reference_pages(): void
    {
        $img = imagecreatetruecolor(200, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        $text = str_repeat('Jogue slots online com gráficos bonitos e rodadas rápidas no celular. ', 12);
        Http::fake([
            'ref.test/img/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            'ref.test/promocoes' => Http::response('<html><head><title>Promoções</title></head><body><main><h1>Promoções</h1><p>Bônus de boas-vindas de R$ 20 para novos jogadores até 31/12/2026.</p><p>'.$text.'</p></main></body></html>'),
            'ref.test/cadastro' => Http::response('<html><body><p>Crie sua conta</p></body></html>'),
            'ref.test/*' => Http::response('<html><head><title>Ref Casino</title></head><body><header><nav><ul><li><a href="/">Início</a></li><li><a href="/jogos">Jogos</a></li><li><a href="/promocoes">Promoções</a></li><li><a href="/cadastro">Cadastre-se</a></li></ul></nav></header>
                <main><h1>Ref Casino</h1><p>'.$text.'</p><div class="games"><a href="/jogos/fortune-tiger"><img src="/img/ft.png" alt="ft.png"><span>Fortune Tiger</span></a><a href="/jogos/gates"><img src="/img/go.png" alt="Gates of Olympus"></a></div></main></body></html>'),
        ]);
        $this->fakeAi([
            'site_data' => [
                'game' => [
                    ['title' => 'Fortune Tiger', 'slug' => 'fortune-tiger', 'summary' => 'Slot com tema de tigre da PG Soft.', 'image' => '', 'provider' => 'PG Soft', 'themes' => ['Animais'], 'tags' => ['hot', 'spicy'], 'rtp' => '96,81%'],
                    ['title' => 'Gates of Olympus', 'slug' => 'gates-of-olympus', 'summary' => 'Slot de mitologia.', 'image' => 'https://ref.test/img/go.png', 'provider' => 'Pragmatic Play'],
                ],
                'promotion' => [
                    ['title' => 'Bônus de boas-vindas', 'slug' => 'boas-vindas', 'summary' => 'Oferta para novos jogadores.', 'tags' => ['new_user'], 'reward' => 'R$ 20', 'requirement' => 'Depósito de R$ 50', 'ends_at' => '2026-12-31'],
                ],
                'provider' => [['title' => 'PG Soft', 'slug' => 'pg-soft', 'summary' => 'Estúdio de slots.']],
            ],
            'entry_content' => ['items' => ['fortune-tiger' => ['details' => ['heading' => 'Sobre o Fortune Tiger', 'html' => '<p>Fortune Tiger é um slot com tema de tigre e rodadas rápidas.</p><p>Pague 5000x a aposta!</p>']]]],
        ]);
        $site = $this->gameSite(['reference_url' => 'https://ref.test/', 'reference_import_images' => true]);

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));
        $site->refresh();

        $this->assertNotNull($site->structure, 'the chosen structure is stored');
        Http::assertSent(fn ($r) => $r->url() === 'https://ref.test/promocoes');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'cadastro'));

        $tiger = $site->entries()->where('slug', 'fortune-tiger')->first();
        $this->assertSame('PG Soft', $tiger->get('provider'));
        $this->assertSame(['hot'], $tiger->tags(), 'unknown tags are dropped');
        $this->assertNull($tiger->get('rtp'), 'a figure the sources do not state is dropped');
        $this->assertNotNull($tiger->image, 'image matched by the tile name');
        $this->assertStringNotContainsString('5000x', $tiger->sections['details']['html']);
        $this->assertNotNull($site->entries()->where('slug', 'gates-of-olympus')->value('image'));

        $promo = $site->entries()->where('collection', 'promotion')->first();
        $this->assertSame('R$ 20', $promo->get('reward'), 'reference amounts are kept');
        $this->assertNull($promo->get('requirement'), 'invented amounts are not');
        $this->assertSame('2026-12-31', $promo->get('ends_at'));

        $dir = StaticSiteBuilder::dirFor($site);
        $games = $site->pages()->where('type', 'games')->value('slug');
        $this->assertFileExists("{$dir}/{$games}/fortune-tiger/index.html");
        $this->assertStringContainsString('Sobre o Fortune Tiger', file_get_contents("{$dir}/{$games}/fortune-tiger/index.html"));
    }

    public function test_admin_shows_site_data_for_game_sites(): void
    {
        $site = $this->gameSite();
        $this->addEntries($site);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get("https://admin.test/admin/sites/{$site->id}/edit")->assertOk();
        $this->actingAs($admin)->get('https://admin.test/admin/sites/create')->assertOk();
        Livewire::actingAs($admin)
            ->test(EntriesRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
            ->assertOk()->assertSee('Fortune Tiger');
    }
}
