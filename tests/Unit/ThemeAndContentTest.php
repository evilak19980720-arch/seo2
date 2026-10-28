<?php

namespace Tests\Unit;

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
}
