<?php

namespace Tests\Feature;

use App\Services\Render\SiteRenderer;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Theme\DesignConfigValidator;
use App\Services\Theme\Variants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The restyled slots variants: registered, styled, tagged and only shipped to the sites using them. */
class BlockVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_restyled_variant_has_a_known_base_and_its_own_stylesheet(): void
    {
        $count = 0;
        $originals = [];
        foreach (config('theme.blocks') as $block => $list) {
            $originals[$block] = array_values(array_diff($list, array_keys(config("theme.variants.{$block}", []))));
        }
        foreach (config('theme.variants') as $block => $variants) {
            foreach ($variants as $name => $def) {
                $count++;
                $this->assertContains($def['base'], $originals[$block], "{$block}.{$name} base");
                $this->assertContains($name, config("theme.blocks.{$block}"), "{$block}.{$name} offered");
                $this->assertFileExists(Variants::file($block, $name));
                $this->assertStringContainsString('--'.$name, file_get_contents(Variants::file($block, $name)), "{$block}.{$name} styles its own class");
            }
        }
        $this->assertSame(100, $count);
        $this->assertCount($count, glob(resource_path('theme/variants/*.css')), 'no stylesheet without a variant');
    }

    public function test_a_variant_renders_with_its_base_markup_plus_its_own_class(): void
    {
        $html = '<section class="section cta cta--banner"><div class="cta__box"></div></section>';

        $this->assertSame('<section data-stage class="section cta cta--banner cta--jackpot"><div class="cta__box"></div></section>', Variants::tag($html, 'cta', 'jackpot'));
        $this->assertSame($html, Variants::tag($html, 'cta', 'banner'), 'original variants are untouched');
        $this->assertSame('banner', Variants::base('cta', 'jackpot'));
        $this->assertSame('<div class="entry-list entry-list--cards">', Variants::tag('<div class="entry-list entry-list--cards">', 'entry_list', 'rail'), 'a block that fell back to another layout keeps it');
    }

    public function test_site_stylesheet_carries_only_the_variants_the_site_uses(): void
    {
        $site = $this->makeSite(['status' => 'live']);
        $config = (new DesignConfigValidator)->validate(['nav' => 'neon', 'footer' => 'gold'], $site->typeConfig());
        $config['pages']['home'] = [['block' => 'hero', 'variant' => 'jackpot'], ['block' => 'faq', 'variant' => 'accordion']];
        $site->update(['design_config' => $config]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);
        $site = $site->fresh(['domain', 'brandProfile']);
        app(StaticSiteBuilder::class)->buildAll($site);
        $css = (new SiteRenderer($site, StaticSiteBuilder::dirFor($site)))->baseCss();

        $this->assertStringContainsString('.hero--jackpot', $css);
        $this->assertStringContainsString('.nav--neon', $css);
        $this->assertStringContainsString('.footer--gold', $css);
        $this->assertStringNotContainsString('.hero--royal', $css);
        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertMatchesRegularExpression('/<section data-stage class="hero hero--stacked[^"]* hero--jackpot/', $html);
        $this->assertMatchesRegularExpression('/class="nav nav--bar nav--neon/', $html);
    }
}
