<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\SiteStatus;
use App\Models\AiProvider;
use App\Services\Content\ArticlePipeline;
use App\Services\Content\PublishScheduler;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\ReferenceLibrary;
use App\Services\Sites\SiteDataGenerator;
use App\Services\Sites\SiteGenerator;
use App\Services\Theme\ReferenceAnalyzer;
use App\Support\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IllustrationTest extends TestCase
{
    use RefreshDatabase;

    private static function png(int $w = 800, int $h = 500): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), 120, 60));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    private function illustratedArticle(): string
    {
        return '<figure data-illustration="A spaceship dodging asteroids"></figure>'.self::portugueseArticle()
            .'<figure data-illustration="Collecting golden stars"></figure>';
    }

    private function liveSite(array $brand = [])
    {
        $site = $this->makeSite(['domain' => 'ill-'.uniqid().'.com.br', 'status' => SiteStatus::Live, 'launched_at' => now()], $brand);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site->fresh());
        $site->keywords()->create(['keyword' => 'como jogar galaxy run', 'source' => 'ai']);

        return $site->fresh(['domain', 'brandProfile']);
    }

    public function test_article_placeholders_become_ai_pictures(): void
    {
        $calls = 0;
        Http::fake(['ai.test/v1/images/*' => function () use (&$calls) {
            $calls++;

            return Http::response(['data' => [['b64_json' => base64_encode(self::png())]]]);
        }]);
        $this->fakeAi(['article' => ['title' => 'Como jogar Galaxy Run', 'slug' => 'como-jogar-galaxy-run', 'meta_title' => 'Como jogar', 'meta_description' => 'Guia.', 'html' => $this->illustratedArticle()], 'polished' => $this->illustratedArticle()]);
        AiProvider::query()->update(['image_model' => 'gpt-image-1']);
        $site = $this->liveSite();

        $article = app(ArticlePipeline::class)->generateNext($site);

        $this->assertSame(2, $calls);
        $this->assertStringNotContainsString('data-illustration', $article->body);
        $this->assertSame(2, preg_match_all('/<img[^>]+src="media:articles\/'.$site->id.'\/[0-9a-f]{32}\.png"/', $article->body));
        $this->assertStringContainsString('alt="A spaceship dodging asteroids"', $article->body);
        foreach (glob(storage_path('app/public/articles/'.$site->id.'/*')) ?: [] as $f) {
            $this->assertNotFalse(getimagesize($f));
        }

        $article->update(['status' => ArticleStatus::Queued]);
        app(PublishScheduler::class)->publish($article->fresh());
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/'.$article->path().'/index.html');
        $this->assertStringNotContainsString('media:', $html);
        $this->assertMatchesRegularExpression('#<figure><img[^>]+src="[^"]*/media/[^"]+"[^>]+width="\d+"#', $html);
    }

    public function test_without_an_image_model_brand_pictures_or_nothing(): void
    {
        $this->fakeAi(['article' => ['title' => 'Como jogar Galaxy Run', 'slug' => 'como-jogar-galaxy-run', 'meta_title' => 'Como jogar', 'meta_description' => 'Guia.', 'html' => $this->illustratedArticle()], 'polished' => $this->illustratedArticle()]);
        Media::put('brand/shot.png', self::png());
        $site = $this->liveSite(['media' => [['type' => 'image', 'path' => 'brand/shot.png', 'alt' => 'Tela']]]);

        $body = app(ArticlePipeline::class)->generateNext($site)->body;

        $this->assertSame(1, substr_count($body, 'src="media:brand/shot.png"'), 'each spare picture is used once');
        $this->assertStringNotContainsString('data-illustration', $body);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/images/'));
    }

    public function test_promotion_cards_keep_their_pictures_and_detail_pages_are_read(): void
    {
        $text = 'Ganhe um bônus no primeiro depósito e aproveite rodadas extras em todos os jogos de slots da casa durante a semana.';
        Http::fake([
            'promo.test/promocoes' => Http::response('<html><body><main><h1>Promoções</h1>
                <div class="promo-list">
                  <div class="promo"><a href="/promo/boas-vindas"><img src="/img/p1.jpg"></a><div class="info"><h3>Bônus de boas-vindas</h3><p>'.$text.'</p></div></div>
                  <div class="promo"><img src="/img/p2.jpg" alt="banner_02.png"><div><span class="promo-title">Cashback semanal</span><p>Receba parte das suas perdas de volta toda segunda-feira, sem precisar de código promocional algum.</p></div><a href="/promo/cashback">Ver mais</a></div>
                </div></main></body></html>'),
            'promo.test/promo/cashback' => Http::response('<html><body><main><h1>Cashback semanal</h1><img src="/img/cashback-big.jpg" alt="Cashback"><p>'.str_repeat('Regras do cashback semanal para todos os jogadores. ', 4).'</p></main></body></html>'),
            'promo.test/*' => Http::response('<html><body><main><h1>Página</h1><p>'.str_repeat('Texto da página. ', 10).'</p></main></body></html>'),
        ]);
        $analyzer = app(ReferenceAnalyzer::class);

        $pages = $analyzer->crawl(['url' => 'https://promo.test/', 'links' => [['url' => 'https://promo.test/promocoes', 'text' => 'Promoções']]]);

        $cards = collect($pages)->firstWhere('label', 'Promoções')['cards'];
        $welcome = collect($cards)->firstWhere('name', 'Bônus de boas-vindas');
        $this->assertSame('https://promo.test/img/p1.jpg', $welcome['image']);
        $this->assertStringContainsString('primeiro depósito', $welcome['text']);
        $this->assertStringStartsWith('Ganhe um bônus', $welcome['text']);
        $cashback = collect($cards)->firstWhere('name', 'Cashback semanal');
        $this->assertSame('https://promo.test/img/p2.jpg', $cashback['image']);
        $this->assertSame('https://promo.test/promo/cashback', $cashback['url'], 'the "Ver mais" link beside the picture');

        $detail = collect($pages)->firstWhere('url', 'https://promo.test/promo/cashback');
        $this->assertTrue($detail['detail']);
        $this->assertSame('https://promo.test/img/cashback-big.jpg', $detail['cards'][0]['image']);
    }

    public function test_items_match_pictures_by_similar_names(): void
    {
        $tiles = collect([
            ['name' => 'Bônus de boas-vindas 100%', 'image' => 'a.jpg'],
            ['name' => 'Torneio', 'image' => 'b.jpg', 'text' => 'Torneio semanal de slots com prêmios em dinheiro'],
            ['name' => 'Sem foto', 'image' => null],
        ]);
        $gen = app(SiteDataGenerator::class);

        $this->assertSame('a.jpg', $gen->matchTile('Bônus de Boas-Vindas', $tiles)['image']);
        $this->assertSame('b.jpg', $gen->matchTile('Torneio semanal de slots', $tiles)['image']);
        $this->assertNull($gen->matchTile('Programa VIP', $tiles));
        $this->assertNull($gen->matchTile('Sem foto', $tiles));
    }

    public function test_a_picture_the_model_picked_must_belong_to_the_item(): void
    {
        $tiles = collect([['name' => 'Sweet Bonanza', 'image' => 'https://ref.test/a1.png'], ['name' => '', 'image' => 'https://ref.test/a2.png']]);
        $gen = app(SiteDataGenerator::class);

        $this->assertFalse($gen->urlShows('https://ref.test/a1.png', 'Gates of Olympus', $tiles), 'another game\'s tile');
        $this->assertTrue($gen->urlShows('https://ref.test/a1.png', 'Sweet Bonanza 1000', $tiles));
        $this->assertTrue($gen->urlShows('https://cdn.ref.test/games/gates_of_olympus-cover.webp', 'Gates of Olympus', $tiles), 'file name spells the name');
        $this->assertFalse($gen->urlShows('https://cdn.ref.test/games/12345.webp', 'Gates of Olympus', $tiles));
    }

    public function test_the_library_keeps_each_card_with_its_picture_text_and_terms(): void
    {
        $found = app(ReferenceLibrary::class)->collect([
            ['url' => 'https://ref.test/', 'cards' => [
                ['name' => 'Cashback semanal', 'image' => 'https://ref.test/p/cash.jpg', 'url' => 'https://ref.test/promo/cashback', 'text' => '10% de volta toda segunda'],
                ['name' => 'Fortune Tiger', 'image' => 'https://ref.test/g/tiger.webp', 'url' => null],
            ], 'images' => [['url' => 'https://ref.test/banner.jpg', 'alt' => 'Banner'], ['url' => 'https://ref.test/g/tiger.webp', 'alt' => '']]],
            ['url' => 'https://ref.test/promo/cashback/', 'detail' => true, 'content' => 'Cashback de 10% sobre perdas, mínimo R$ 20, válido até 31/12.', 'cards' => [], 'images' => []],
        ]);

        $cash = $found[md5('https://ref.test/p/cash.jpg')];
        $this->assertSame(['card', 'Cashback semanal', '10% de volta toda segunda', 'https://ref.test/promo/cashback'], [$cash['kind'], $cash['name'], $cash['text'], $cash['link']]);
        $this->assertStringContainsString('mínimo R$ 20', $cash['detail'], 'the detail page terms stay with the card');
        $this->assertSame('card', $found[md5('https://ref.test/g/tiger.webp')]['kind'], 'a picture seen as a card stays a card');
        $this->assertSame('image', $found[md5('https://ref.test/banner.jpg')]['kind']);
        $this->assertCount(3, $found);
    }
}
