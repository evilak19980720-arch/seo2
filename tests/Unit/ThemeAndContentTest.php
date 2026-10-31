<?php

namespace Tests\Unit;

use App\Services\Ai\AiResponse;
use App\Services\Content\LanguageDetector;
use App\Services\Content\Similarity;
use App\Services\Render\HtmlSanitizer;
use App\Services\Render\InternalLinker;
use App\Services\Render\SeoHead;
use App\Services\Theme\Color;
use App\Services\Theme\CustomCssValidator;
use Tests\TestCase;

class ThemeAndContentTest extends TestCase
{
    public function test_custom_css_rules(): void
    {
        $this->assertSame([], CustomCssValidator::errors('.btn{background:linear-gradient(90deg,var(--c-primary),var(--c-accent))}'));
        $this->assertNotEmpty(CustomCssValidator::errors('@import url("x.css");'));
        $this->assertNotEmpty(CustomCssValidator::errors('.hero{background:url(https://cdn.evil.test/a.png)}'));
        $this->assertNotEmpty(CustomCssValidator::errors('main p{display:none}'));
        $this->assertNotEmpty(CustomCssValidator::errors('.prose{font-size:0}'));
        $this->assertNotEmpty(CustomCssValidator::errors(str_repeat('a{}', 6000)));
        $this->assertSame([], CustomCssValidator::errors('.cookie{display:none}'), 'non-content elements may be hidden');
    }

    public function test_contrast_repair(): void
    {
        $fixed = Color::ensureContrast('#cccccc', '#ffffff');
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($fixed, '#ffffff'));
    }

    public function test_sanitizer_strips_dangerous_markup(): void
    {
        $html = HtmlSanitizer::clean('<h1>T</h1><p onclick="x()">Oi <a href="javascript:alert(1)">l</a></p><iframe src="x"></iframe><div><p>ok</p></div>');
        $this->assertSame('<h2>T</h2><p>Oi <a>l</a></p><p>ok</p>', $html);
    }

    public function test_internal_linker_links_first_occurrence_outside_headings(): void
    {
        [$html, $linked] = InternalLinker::link('<h2>como jogar</h2><p>Veja como jogar bem. Depois, como jogar de novo.</p>', ['como jogar' => '/guia/']);
        $this->assertSame(['/guia/'], $linked);
        $this->assertSame(1, substr_count($html, '<a href="/guia/">'));
        $this->assertStringContainsString('<h2>como jogar</h2>', $html);
    }

    public function test_language_detection_and_similarity(): void
    {
        $pt = strip_tags(self::portugueseArticle(3));
        $this->assertSame('pt', LanguageDetector::detect($pt));
        $this->assertFalse(LanguageDetector::matches(str_repeat('This is the game and you can play it with the friends that are online. ', 10), 'pt'));

        $a = Similarity::fingerprint($pt);
        $b = Similarity::fingerprint(strip_tags(self::portugueseArticle(5)));
        $c = Similarity::fingerprint('Receita de bolo de chocolate com cobertura de brigadeiro e granulado para festas de aniversário infantis.');
        $this->assertGreaterThan(0.8, Similarity::compare($a, $b));
        $this->assertLessThan(0.2, Similarity::compare($a, $c));
    }

    public function test_meta_limits(): void
    {
        $this->assertLessThanOrEqual(60, mb_strlen(SeoHead::limit(str_repeat('palavra ', 20), 60)));
        $this->assertSame('Curto', SeoHead::limit('Curto', 60));
    }

    public function test_custom_css_is_kept_phone_safe(): void
    {
        $this->assertNotEmpty(CustomCssValidator::errors('.nav{width:1200px}'));
        $this->assertSame([], CustomCssValidator::errors('@media (min-width:960px){.nav{width:1200px}} .a{max-width:1400px;width:320px}'));

        $fixed = CustomCssValidator::mobileSafe('.a{width:1200px;min-width:480px}.b{width:300px}@media (min-width:900px){.c{width:1100px}}');
        $this->assertSame('.a{width:min(1200px,100%);min-width:min(480px,100%)}.b{width:300px}@media (min-width:900px){.c{width:1100px}}', $fixed);
        $this->assertSame([], CustomCssValidator::errors($fixed));
    }

    public function test_broken_or_cut_off_ai_json_is_repaired(): void
    {
        $json = fn (string $text, bool $cut = false) => (new AiResponse($text, truncated: $cut))->json();

        $this->assertSame(['css' => ".a{\n color:red }"], $json("{\"css\":\".a{\n color:red }\"}"));
        $this->assertSame(['a' => [1, 2], 'b' => ['c' => 3]], $json('{"a":[1,2,],"b":{"c":3,}}'));
        $this->assertSame(['design' => ['colors' => ['primary' => '#b01010', 'surface' => '#3a']]], $json('{"design":{"colors":{"primary":"#b01010","surface":"#3a', true));
        $this->assertSame(['design' => ['nav' => 'bar']], $json('{"design":{"nav":"bar","footer":', true));
        $this->assertSame(['ok' => true], $json("Here you go:\n```json\n{\"ok\":true}\n```"));
    }

    public function test_ai_css_backgrounds_carry_readable_text_and_cannot_move_theme_parts(): void
    {
        $colors = ['primary' => '#2563eb', 'on_primary' => '#ffffff', 'secondary' => '#0f172a', 'on_secondary' => '#ffffff', 'accent' => '#f59e0b', 'background' => '#ffffff', 'surface' => '#f8fafc', 'text' => '#111827', 'muted' => '#4b5563', 'link' => '#2563eb'];
        $css = CustomCssValidator::themeSafe(
            '.nav{background:var(--c-secondary);position:fixed} .nav__links a{color:#1f2937;background:#0b1020} .hero::before{position:absolute;background:var(--c-primary)}'
            .' @media (min-width:900px){.card{background:#fde047;float:left}} .nav__menu{display:block;background:#fff;transform:none} .box{background:color-mix(in srgb,var(--c-primary) 20%,transparent)}',
            $colors,
        );

        $this->assertStringContainsString('.nav{background:var(--c-secondary);color:#ffffff;--c-text:#ffffff', $css, 'dark header gets white text and tokens');
        $this->assertStringNotContainsString('fixed', $css);
        $this->assertMatchesRegularExpression('/\.nav__links a\{color:#[0-9a-f]{6};background:#0b1020/', $css);
        $this->assertStringNotContainsString('color:#1f2937', $css, 'dark text on a dark background is lightened');
        $this->assertStringContainsString('.hero::before{position:absolute;background:var(--c-primary)}', $css, 'decorations keep their position');
        $this->assertStringContainsString('@media (min-width:900px){.card{background:#fde047;color:#111827', $css);
        $this->assertStringNotContainsString('float', $css);
        $this->assertStringContainsString('.nav__menu{background:#fff;color:#111827', $css, 'the phone menu keeps its show/hide mechanics');
        $this->assertStringNotContainsString('display:block', $css);
        $this->assertStringContainsString('.box{background:color-mix(in srgb,var(--c-primary) 20%,transparent)}', $css, 'see-through backgrounds are left alone');

        // A footer painted dark with the muted colour as text: every token is a real colour, light enough to read.
        $footer = CustomCssValidator::themeSafe(':root{--c-text:#000;--c-surface:#fff} .footer{background:#15171a;color:var(--c-muted);--c-muted:#0c0506}', $colors + ['muted' => '#a9aeb8']);
        $this->assertStringNotContainsString('--c-text:#000', $footer, 'AI CSS cannot redefine the palette');
        $this->assertStringNotContainsString('var(--c-muted)', $footer);
        $this->assertStringNotContainsString('#0c0506', $footer);
        preg_match('/\.footer\{[^}]*\bcolor:(#[0-9a-f]{6});[^}]*--c-text:(#[0-9a-f]{6});[^}]*--c-muted:(#[0-9a-f]{6})/', $footer, $m);
        $this->assertCount(4, $m, $footer);
        foreach (array_slice($m, 1) as $hex) {
            $this->assertGreaterThanOrEqual(3, Color::contrast($hex, '#15171a'), $hex);
        }
    }

    public function test_ai_css_painted_with_a_token_it_redefines_keeps_the_meant_colour(): void
    {
        $colors = ['primary' => '#fcf485', 'on_primary' => '#111111', 'secondary' => '#f39e27', 'on_secondary' => '#111111', 'background' => '#ffffff', 'surface' => '#f2f2f2', 'text' => '#1a1a1a', 'muted' => '#6b5a52', 'link' => '#656235'];
        // The rule paints its background with --c-text and redefines --c-text as light: text and background used to match.
        $css = CustomCssValidator::themeSafe('.footer{background:var(--c-text);color:#f4ead9} .faq details{background:var(--c-surface);border:1px solid var(--c-text)}', $colors);

        $this->assertStringContainsString('.footer{background:#1a1a1a;color:#f4ead9', $css);
        $this->assertStringContainsString('.faq details{background:#f2f2f2;border:1px solid #1a1a1a', $css);
        preg_match('/\.footer\{background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})/', $css, $m);
        $this->assertGreaterThanOrEqual(4.5, Color::contrast($m[1], $m[2]));
    }

    public function test_bottom_bar_icons_follow_the_page_and_differ_between_sites(): void
    {
        $this->assertSame('promo', \App\Services\Theme\BarIcons::topic('promotions'));
        $this->assertSame('games', \App\Services\Theme\BarIcons::topic('live_games'), 'unknown types fall back to words in their name');
        $this->assertSame('more', \App\Services\Theme\BarIcons::topic('legal_stuff'));
        foreach (\App\Services\Theme\BarIcons::GLYPHS as $topic => $shapes) {
            $this->assertCount(3, array_unique($shapes), $topic);
        }
        $shapes = collect(range(1, 30))->map(fn ($site) => \App\Services\Theme\BarIcons::svg('games', (string) $site))->unique();
        $this->assertCount(3, $shapes, 'sites draw different shapes');
        $this->assertSame(\App\Services\Theme\BarIcons::svg('games', '7#1'), \App\Services\Theme\BarIcons::svg('games', '7#1'), 'stable per site');
    }
}
