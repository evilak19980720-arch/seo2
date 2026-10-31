<?php

namespace Tests\Feature;

use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\RelationManagers\EntriesRelationManager;
use App\Filament\Resources\Sites\RelationManagers\ReferenceItemsRelationManager;
use App\Models\GenerationTask;
use App\Models\GoneUrl;
use App\Models\Site;
use App\Models\User;
use App\Services\Render\MediaPublisher;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeContext;
use App\Services\Sites\SiteGenerator;
use App\Services\Sites\SiteStructure;
use App\Services\Theme\DesignConfigValidator;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
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
                    'timeline', 'glossary', 'checklist', 'table' => ['heading' => 'Bloco '.$section['key'], 'items' => [['title' => 'Item A '.$section['key'], 'text' => 'Texto A.'], ['title' => 'Item B', 'text' => 'Texto B.']]],
                    'callout' => ['heading' => 'Aviso '.$section['key'], 'text' => 'Atenção ao '.$section['key'].'.'],
                    'quote' => ['text' => 'Citação '.$section['key'], 'cite' => 'Equipe Galaxy Run'],
                    default => ['heading' => 'Lista '.$section['key']],
                };
            }
            $site->pages()->create([
                'type' => $type, 'slug' => $type === 'home' ? '' : ($def['slug']['pt'] ?? $def['slug']['en']),
                'title' => 'Página '.$type, 'content' => $content,
            ]);
        }
    }

    /** Pin the game preset to one positioning (the "promo" one has games, lobby and promotions). */
    protected function positioning(string $name = 'promo'): array
    {
        config(['site_types.game.positionings' => array_intersect_key(config('site_types.game.positionings'), [$name => 1])]);

        return config('site_types.game');
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

    public function test_every_page_type_has_a_title_in_each_bundled_language(): void
    {
        foreach (array_keys(config('site_types')) as $siteType) {
            foreach (array_keys(config("site_types.{$siteType}.pages") ?? []) as $type) {
                foreach (['en', 'pt', 'es'] as $lang) {
                    $this->assertTrue(Lang::has("theme.page_titles.{$type}", $lang, false), "{$siteType}.{$type} has no {$lang} title");
                }
            }
        }
    }

    public function test_game_sites_get_the_mobile_bar_by_default(): void
    {
        $validator = new DesignConfigValidator;
        $this->assertTrue($validator->validate(['navigation' => ['mobile_bar' => false]], $this->gameSite()->typeConfig())['navigation']['mobile_bar']);
        $this->assertFalse($validator->validate([], $this->makeSite()->typeConfig())['navigation']['mobile_bar']);
    }

    public function test_sites_get_different_phone_header_menu_and_bar_combinations(): void
    {
        $validator = new DesignConfigValidator;
        $type = $this->gameSite()->typeConfig();
        $combos = collect(['#e11d48', '#2563eb', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#ca8a04', '#db2777'])
            ->map(fn ($c) => collect($validator->validate(['colors' => ['primary' => $c]], $type)['navigation'])->only(array_keys(DesignConfigValidator::MOBILE))->implode('/'));
        $this->assertGreaterThan(4, $combos->unique()->count());
        $this->assertSame($combos[0], collect($validator->validate(['colors' => ['primary' => '#e11d48']], $type)['navigation'])->only(array_keys(DesignConfigValidator::MOBILE))->implode('/'), 'stable per site');

        $chosen = $validator->validate(['navigation' => ['mobile_header' => 'tabs', 'mobile_menu' => 'sheet', 'mobile_bar_style' => 'pill']], $type)['navigation'];
        $this->assertSame(['tabs', 'sheet', 'pill'], [$chosen['mobile_header'], $chosen['mobile_menu'], $chosen['mobile_bar_style']]);
    }

    public function test_structure_is_stable_random_and_respects_conditions(): void
    {
        $type = config('site_types.game');
        $a = $this->gameSite();
        $first = SiteStructure::choose($a, $type);
        $this->assertSame($first, SiteStructure::choose($a, $type), 'same site, same structure');

        foreach (['home', 'games', 'help', 'about', 'responsible', 'terms', 'privacy', 'sitemap', 'search'] as $required) {
            $this->assertArrayHasKey($required, $first['pages']);
        }
        // One positioning decides the menu: 5-7 entries after home, News among them, every entry a chosen page.
        $this->assertArrayHasKey($first['positioning'], $type['positionings']);
        $this->assertContains('blog', $first['nav']['menu']);
        $this->assertGreaterThanOrEqual(5, count($first['nav']['menu']));
        $this->assertLessThanOrEqual(7, count($first['nav']['menu']));
        foreach (array_diff($first['nav']['menu'], ['blog']) as $page) {
            $this->assertArrayHasKey($page, $first['pages']);
        }
        // Home has the positioning's sections: the opener first, FAQ and help last, the middle in the site's own order.
        $planned = array_values(array_intersect($type['positionings'][$first['positioning']]['home'], array_column($type['pages']['home']['sections'], 'key')));
        $this->assertEqualsCanonicalizing($planned, $first['pages']['home']);
        $this->assertSame($planned[0], $first['pages']['home'][0], 'the opener stays first');
        $tail = array_values(array_intersect($planned, ['faq', 'support']));
        $this->assertSame($tail, array_slice($first['pages']['home'], -count($tail) ?: count($first['pages']['home'])), 'FAQ and help stay last');
        $this->assertTrue($first['home_order']);
        $applied = array_column(SiteStructure::apply($type, $first)['pages']['home']['sections'], 'key');
        $this->assertSame($first['pages']['home'], $applied, 'the page is built in that order');
        $this->assertLessThanOrEqual(34, count($first['pages']));
        $positionings = collect(range(1, 12))->map(fn () => SiteStructure::choose($this->gameSite(), $type)['positioning'])->unique();
        $this->assertGreaterThan(1, $positionings->count(), 'positioning is drawn per site');
        // No accounts, no payments, no licence: none of those pages or sections.
        $this->assertArrayNotHasKey('account', $first['pages']);
        $this->assertNotContains('register_login', $first['pages']['guide'] ?? []);
        $this->assertNotContains('payments', $first['pages']['help']);
        $this->assertNotContains('license', $first['pages']['fairness'] ?? []);
        foreach ($first['pages'] as $sections) {
            $this->assertGreaterThanOrEqual(1, count($sections));
        }

        // Brand sites only: no account or payment pages and sections exist in the pool.
        $pool = json_encode($type['pages']);
        foreach (['account', 'payments', 'payment_terms'] as $page) {
            $this->assertArrayNotHasKey($page, $type['pages']);
        }
        foreach (['register', 'login', 'deposit', 'withdraw', 'self_exclusion'] as $key) {
            $this->assertStringNotContainsString('"key":"'.$key.'"', $pool);
        }

        // Long pools are capped; detail pages and block looks are chosen too.
        foreach ($first['pages'] as $page => $sections) {
            $this->assertLessThanOrEqual($type['pages'][$page]['max_sections'] ?? $type['max_sections'], count($sections), $page);
        }
        $this->assertContains('details', $first['collections']['game']);
        $this->assertNotEmpty($first['variants']);

        // Different sites differ somewhere in their combination.
        $shapes = collect(range(1, 6))->map(fn () => json_encode(SiteStructure::choose($this->gameSite(), $type)))->unique();
        $this->assertGreaterThan(1, $shapes->count());

        // A regenerated structure keeps what the site already had.
        $again = SiteStructure::choose($a, $type, ['pages' => ['events' => ['ongoing', 'calendar']] + $first['pages']] + $first);
        $this->assertArrayHasKey('events', $again['pages']);
        $this->assertContains('calendar', $again['pages']['events']);
    }

    public function test_regeneration_draws_a_new_combination_and_a_retry_keeps_it(): void
    {
        $generator = app(SiteGenerator::class);
        $site = $this->gameSite();
        $generator->ensureStructure($site);
        $first = $site->fresh()->structure;

        $seen = [json_encode($first['pages'])];
        $site->generationTasks()->create(['type' => 'full', 'status' => 'succeeded']);
        foreach (range(1, 3) as $n) {
            $generator->ensureStructure($site, reshuffle: true);
            $site->refresh();
            $this->assertSame($n, $site->structure['round']);
            $this->assertNotContains(json_encode($site->structure['pages']), [$seen[count($seen) - 1]], 'each regeneration differs from the last');
            $seen[] = json_encode($site->structure['pages']);
            foreach (['home', 'games', 'about', 'terms', 'privacy'] as $required) {
                $this->assertArrayHasKey($required, $site->structure['pages']);
            }
        }
        $this->assertGreaterThan(2, count(array_unique($seen)));

        // A plain (retry) run keeps the current combination.
        $kept = $site->structure;
        $generator->ensureStructure($site);
        $this->assertSame($kept, $site->fresh()->structure);

        // A launched site keeps every page it has (no URL disappears); sections may change.
        $site->update(['launched_at' => now()]);
        foreach (array_keys($kept['pages']) as $type) {
            $site->pages()->create(['type' => $type, 'slug' => $type === 'home' ? '' : $type, 'title' => $type, 'content' => []]);
        }
        $generator->ensureStructure($site, reshuffle: true);
        $this->assertSame([], array_diff(array_keys($kept['pages']), array_keys($site->fresh()->structure['pages'])));
    }

    public function test_game_site_renders_sections_lobby_entries_and_sitemap(): void
    {
        $site = $this->gameSite();
        $site->update(['structure' => SiteStructure::choose($site, $this->positioning())]);
        $site = $site->fresh(['domain', 'brandProfile']);
        $this->fillPages($site);
        $this->addEntries($site);

        $written = (new StaticSiteBuilder)->buildAll($site);
        $dir = StaticSiteBuilder::dirFor($site);

        $gamesSlug = $site->pages()->where('type', 'games')->value('slug');
        $this->assertContains("{$gamesSlug}/fortune-tiger", $written, 'games own the game detail pages');

        // The game lobby (under the game centre) has search and filters; it shares the game detail pages.
        $lobby = file_get_contents("{$dir}/".$site->pages()->where('type', 'hall')->value('slug').'/index.html');
        $this->assertStringContainsString('data-lobby', $lobby);
        $this->assertStringContainsString('data-filter="provider"', $lobby);
        $this->assertStringNotContainsString('data-tab="favorites"', $lobby, 'no personal tabs');
        $this->assertStringContainsString("/{$gamesSlug}/gates-of-olympus/", $lobby);
        $this->assertSame(1, substr_count($lobby, '<h1'));

        $detail = file_get_contents("{$dir}/{$gamesSlug}/fortune-tiger/index.html");
        $this->assertStringContainsString('<h1>Fortune Tiger</h1>', $detail);
        $this->assertStringContainsString('96,5%', $detail, 'known parameters are shown');
        $this->assertStringContainsString('Mahjong Ways', $detail, 'same provider games are related');
        $this->assertStringContainsString('"@type":"VideoGame"', $detail);
        $this->assertStringNotContainsString('Volatilidade', $detail, 'unknown parameters are not invented');
        $this->assertMatchesRegularExpression('#class="see-also__list"><li><a href="/#', $detail, 'a detail page leads on to related pages');

        $home = file_get_contents("{$dir}/index.html");
        $this->assertSame(1, substr_count($home, '<h1'));
        $this->assertStringContainsString('entry-card', $home);

        $promos = file_get_contents("{$dir}/".$site->pages()->where('type', 'promotions')->value('slug').'/index.html');
        $this->assertStringContainsString('Bônus de boas-vindas', $promos);
        $this->assertStringContainsString('R$ 20', $promos);

        $map = file_get_contents("{$dir}/".$site->pages()->where('type', 'sitemap')->value('slug').'/index.html');
        $this->assertStringContainsString('Gates of Olympus', $map);

        $xml = file_get_contents("{$dir}/sitemap.xml");
        $this->assertStringContainsString("/{$gamesSlug}/sweet-bonanza/", $xml);

        // Legal pages sit in the footer's legal list, not the page list; main nav is capped.
        $menu = $site->fresh()->design_config ?? (new ThemeContext($site, new MediaPublisher($dir)))->design;
        $this->assertLessThanOrEqual(7, count($menu['menu']), 'home plus at most 7 entries');
    }

    public function test_generation_fills_site_data_from_the_reference_pages(): void
    {
        $this->positioning();
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

        // Collected cards keep name, picture and link together; items take the stored picture of their own card.
        $cards = $site->referenceItems()->where('kind', 'card')->get()->keyBy('name');
        $this->assertSame('https://ref.test/img/ft.png', $cards['Fortune Tiger']->image_url);
        $this->assertSame('https://ref.test/jogos/fortune-tiger', $cards['Fortune Tiger']->link);
        $this->assertNotNull($cards['Fortune Tiger']->image_path);
        $this->assertSame($cards['Fortune Tiger']->image_path, $tiger->image);
        $this->assertTrue($site->referenceItems()->whereNull('image_path')->doesntExist(), 'every collected picture is uploaded');

        $promo = $site->entries()->where('collection', 'promotion')->first();
        $this->assertSame('R$ 20', $promo->get('reward'), 'reference amounts are kept');
        $this->assertNull($promo->get('requirement'), 'invented amounts are not');
        $this->assertSame('2026-12-31', $promo->get('ends_at'));

        $dir = StaticSiteBuilder::dirFor($site);
        $games = $site->pages()->where('type', 'games')->value('slug');
        $this->assertFileExists("{$dir}/{$games}/fortune-tiger/index.html");
        $this->assertStringContainsString('Sobre o Fortune Tiger', file_get_contents("{$dir}/{$games}/fortune-tiger/index.html"));
    }

    public function test_content_blocks_categories_search_and_empty_sections(): void
    {
        $site = $this->gameSite();
        $structure = SiteStructure::choose($site, config('site_types.game'));
        $structure['pages'] = array_merge($structure['pages'], [
            'games' => ['overview', 'lobby', 'categories'],
            'slot_types' => ['intro', 'megaways', 'cluster', 'ways'],
            'academy' => ['basics', 'glossary', 'wild', 'scatter'],
            'brand_story' => ['story', 'founder', 'history'],
            'events' => ['ongoing', 'upcoming', 'calendar'],
            'search' => ['search'],
        ]);
        $site->update(['structure' => $structure]);
        $site = $site->fresh(['domain', 'brandProfile']);
        $this->fillPages($site);
        // "scatter" got no copy: it gets no page instead of showing filler.
        $academy = $site->pages()->where('type', 'academy')->first();
        $academy->update(['content' => array_diff_key($academy->content, ['scatter' => 1])]);
        $this->addEntries($site);
        $site->entries()->where('slug', 'gates-of-olympus')->first()->update(['data' => ['provider' => 'Pragmatic Play', 'types' => ['megaways'], 'theme_keys' => ['greek'], 'rtp' => '96,5%', 'volatility' => 'Alta', 'max_win' => '5.000x']]);
        $tiger = $site->entries()->where('slug', 'fortune-tiger')->first();
        $tiger->update(['data' => $tiger->data + ['types' => ['ways']]]);
        $site->entries()->create(['collection' => 'event', 'slug' => 'torneio', 'title' => 'Torneio de verão', 'data' => ['starts_at' => now()->addDays(3)->toDateString(), 'ends_at' => now()->addDays(10)->toDateString()]]);

        $written = (new StaticSiteBuilder)->buildAll($site);
        $dir = StaticSiteBuilder::dirFor($site);
        $slug = fn ($type) => $site->pages()->where('type', $type)->value('slug');
        $page = fn ($type) => file_get_contents("{$dir}/".$slug($type).'/index.html');

        // A type is a level-3 page under the type index, not a section of it.
        $types = $page('slot_types');
        $this->assertStringContainsString('href="/'.$slug('slot_types').'/megaways/"', $types);
        $this->assertStringNotContainsString('Gates of Olympus</a></h3>', $types, 'the index lists the types, not their games');
        $this->assertStringNotContainsString('/cluster/', $types, 'a type without games gets no page');
        $this->assertContains($slug('slot_types').'/megaways', $written);
        $this->assertNotContains($slug('slot_types').'/cluster', $written);
        $megaways = file_get_contents("{$dir}/".$slug('slot_types').'/megaways/index.html');
        $this->assertStringContainsString('Gates of Olympus', $megaways);
        $this->assertSame(1, substr_count($megaways, '<h1'));
        $this->assertMatchesRegularExpression('#breadcrumb.*href="https://[^"]+/'.$slug('games').'/".*href="https://[^"]+/'.$slug('slot_types').'/"#s', $megaways, 'home › games › types › megaways');

        // Item parameters stay on the item: the game page links its type and explains RTP in the glossary.
        $gates = file_get_contents("{$dir}/".$slug('games').'/gates-of-olympus/index.html');
        $this->assertStringContainsString('href="/'.$slug('slot_types').'/megaways/"', $gates);
        $this->assertStringContainsString('href="/'.$slug('academy').'/#glossary"', $gates);
        foreach ($site->fresh()->typeConfig()['pages'] as $type => $def) {
            $this->assertNotContains($type, ['game_math', 'lobby', 'news', 'status', 'behind_scenes'], 'no column for an attribute or a duplicate list');
        }

        // Knowledge: the glossary stays on the index, each mechanic is an article with the games that use it.
        $academy = $page('academy');
        $this->assertStringContainsString('glossary__items', $academy);
        $this->assertStringContainsString('href="/'.$slug('academy').'/wild/"', $academy);
        $this->assertStringNotContainsString('/scatter/', $academy);
        $wild = file_get_contents("{$dir}/".$slug('academy').'/wild/index.html');
        $this->assertStringContainsString('Texto da seção wild', $wild);
        $this->assertStringContainsString('Fortune Tiger', $wild, 'games with a wild symbol are listed under the article');

        $story = $page('brand_story');
        $this->assertStringContainsString('<blockquote>', $story);
        $this->assertStringContainsString('timeline__list', $story);
        $this->assertStringContainsString('Torneio de verão', $page('events'));
        $this->assertStringContainsString('data-search', $page('search'));

        $index = json_decode(file_get_contents("{$dir}/search.json"), true);
        $this->assertContains('Fortune Tiger', array_column($index, 't'));
        $this->assertContains('Lista megaways', array_column($index, 't'));
        $this->assertStringContainsString('/'.$slug('slot_types').'/megaways/', file_get_contents("{$dir}/sitemap.xml"));
        foreach ([$academy, $story, $types] as $html) {
            $this->assertSame(1, substr_count($html, '<h1'));
        }
    }

    public function test_split_pages_keep_only_children_the_data_fills(): void
    {
        $site = $this->gameSite();
        $this->addEntries($site);
        foreach (['a', 'b', 'c'] as $n) {
            $site->entries()->create(['collection' => 'game', 'slug' => "mega-{$n}", 'title' => "Mega {$n}", 'data' => ['types' => ['megaways'], 'mechanics' => ['Free spins']]]);
        }
        $site->entries()->create(['collection' => 'game', 'slug' => 'cluster-1', 'title' => 'Cluster 1', 'data' => ['types' => ['cluster']]]);
        $config = config('site_types.game');
        $structure = ['pages' => ['slot_types' => ['intro', 'cluster'], 'academy' => ['basics', 'glossary']]];

        $fitted = SiteStructure::fit($site, $config, $structure);
        $this->assertSame(['intro', 'megaways'], $fitted['pages']['slot_types'], 'three games make a type page, one does not');
        $this->assertContains('free_spins', $fitted['pages']['academy'], 'a mechanic the games use gets an article');
        $this->assertContains('cascades', $fitted['pages']['academy'], '"Tumble" counts as cascading reels');
        $this->assertContains('wild', $fitted['pages']['academy']);
        $this->assertNotContains('jackpots', $fitted['pages']['academy'], 'no article about a mechanic no game has');
        $this->assertLessThanOrEqual(2 + 8, count($fitted['pages']['academy']));

        // A launched site keeps a child page that still lists something.
        $site->update(['launched_at' => now()]);
        $this->assertContains('cluster', SiteStructure::fit($site, $config, $structure)['pages']['slot_types']);
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

        $site->referenceItems()->create(['kind' => 'card', 'name' => 'Cashback semanal', 'text' => '10% de volta', 'image_url' => 'https://ref.test/c.jpg', 'image_hash' => md5('https://ref.test/c.jpg'), 'image_path' => 'brand/c.jpg', 'width' => 600, 'height' => 300]);
        Livewire::actingAs($admin)
            ->test(ReferenceItemsRelationManager::class, ['ownerRecord' => $site, 'pageClass' => EditSite::class])
            ->assertOk()->assertSee('Cashback semanal')->assertSee('600×300');
    }

    public function test_offer_tags_are_tabs_and_merged_pages_redirect(): void
    {
        $site = $this->gameSite();
        $site->update(['structure' => SiteStructure::choose($site, $this->positioning())]);
        $site = $site->fresh(['domain', 'brandProfile']);
        $this->fillPages($site);
        $this->addEntries($site);
        $site->entries()->create(['collection' => 'promotion', 'slug' => 'natal', 'title' => 'Especial de Natal', 'data' => ['tags' => ['festival'], 'ends_at' => now()->addMonth()->toDateString()]]);
        (new StaticSiteBuilder)->buildAll($site);

        $promos = file_get_contents(StaticSiteBuilder::dirFor($site).'/'.$site->pages()->where('type', 'promotions')->value('slug').'/index.html');
        $this->assertStringContainsString('data-tab="new_user"', $promos);
        $this->assertStringContainsString('data-tab="festival"', $promos);
        $this->assertStringNotContainsString('id="new_user"', $promos, 'tags are filters, not sections');

        GoneUrl::create(['host' => $site->domain->name, 'path' => 'rtp-e-volatilidade', 'to' => '/guia-de-slots/']);
        $this->get('https://'.$site->domain->name.'/rtp-e-volatilidade/')->assertRedirect('https://'.$site->domain->name.'/guia-de-slots/')->assertStatus(301);
    }

    public function test_tiny_item_pictures_never_squeeze_cards_into_slivers(): void
    {
        $site = $this->gameSite();
        $site->update(['structure' => SiteStructure::choose($site, $this->positioning())]);
        $site = $site->fresh(['domain', 'brandProfile']);
        $this->fillPages($site);
        $this->addEntries($site);
        $im = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($im);
        Media::put('logos/p.png', ob_get_clean());
        foreach (['JILI', 'FA CHAI', 'KA Gaming', 'JDB', 'CQ9', 'Spribe'] as $name) {
            $site->entries()->create(['collection' => 'provider', 'slug' => Str::slug($name), 'title' => $name, 'image' => 'logos/p.png', 'summary' => "{$name} é um estúdio de slots para celular."]);
        }
        $site->entries()->where('slug', 'pg-soft')->update(['image' => 'logos/p.png']);

        (new StaticSiteBuilder)->buildAll($site->fresh(['domain', 'brandProfile']));
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/'.$site->pages()->where('type', 'providers')->value('slug').'/index.html');
        preg_match_all('/--tile-cap:(\d+)px/', $html, $m);
        $this->assertNotEmpty($m[1], 'small logos get a capped card width');
        $this->assertGreaterThanOrEqual(300, min($m[1]), 'never a column narrower than a readable card');
        $this->assertMatchesRegularExpression('/tiles--small\{grid-template-columns:repeat\(auto-fill,minmax\(min\(100%,max\(150px,/', file_get_contents(resource_path('theme/base.css')));
    }

    public function test_menu_is_drawn_per_site_with_games_and_news_always_and_the_game_centre_under_games(): void
    {
        $type = config('game_site');
        $menus = [];
        foreach (range(1, 120) as $seed) {
            $plan = SiteStructure::plan($type, new \Random\Randomizer(new \Random\Engine\Mt19937($seed)), ['app' => true], null, 0);
            $this->assertSame('games', $plan['menu'][0], 'games comes first');
            $this->assertContains('blog', $plan['menu']);
            $this->assertGreaterThanOrEqual(5, count($plan['menu']));
            $this->assertLessThanOrEqual(7, count($plan['menu']));
            foreach ($type['game_centre'] as $page) {
                $this->assertTrue(in_array($page, $plan['menu'], true) || ($plan['parents'][$page] ?? null) === 'games', "{$page} is in the game centre");
            }
            $this->assertLessThanOrEqual($type['dropdown_max'], max(array_count_values($plan['parents']) ?: [0]));
            $menus[] = implode(',', $plan['menu']);
        }
        $this->assertGreaterThan(80, count(array_unique($menus)), 'menus differ between sites, also in order');
    }
}
