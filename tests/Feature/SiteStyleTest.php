<?php

namespace Tests\Feature;

use App\Services\Theme\SiteStyle;
use App\Services\Theme\Variants;
use Tests\TestCase;

class SiteStyleTest extends TestCase
{
    /** What the model answers for every site built from the same reference. */
    private const SAME_ANSWER = [
        'colors' => ['primary' => '#ffcc00', 'background' => '#0b0b0f'],
        'nav' => 'portal', 'footer' => 'dark', 'radius' => 'md', 'fonts' => ['heading' => 'montserrat', 'body' => 'inter'],
        'navigation' => ['buttons' => 1, 'mobile_bar' => true, 'mobile_menu' => 'drawer'],
        'pages' => ['home' => [['block' => 'banner', 'variant' => 'slider'], ['block' => 'card_grid', 'variant' => 'tiles'], ['block' => 'faq', 'variant' => 'accordion']]],
    ];

    private function look(array $c): string
    {
        return json_encode([$c['nav'], $c['footer'], $c['radius'], $c['fonts'], $c['style'], $c['navigation']['mobile_header'], $c['navigation']['mobile_menu'], array_column($c['pages']['home'], 'variant')]);
    }

    public function test_sites_from_the_same_reference_get_their_own_stable_look(): void
    {
        $looks = collect(range(1, 30))->map(fn ($id) => SiteStyle::for($id)->apply(self::SAME_ANSWER));

        $this->assertSame($this->look($looks[0]), $this->look(SiteStyle::for(1)->apply(self::SAME_ANSWER)), 'generated again: same look');
        $this->assertCount(30, $looks->map(fn ($c) => $this->look($c))->unique(), 'no two sites look the same');
        $this->assertGreaterThanOrEqual(3, $looks->pluck('nav')->unique()->count());
        $this->assertGreaterThanOrEqual(3, $looks->pluck('footer')->unique()->count());
        $this->assertGreaterThanOrEqual(6, $looks->map(fn ($c) => implode('+', $c['fonts']))->unique()->count());
        $this->assertGreaterThanOrEqual(3, $looks->map(fn ($c) => $c['pages']['home'][0]['variant'])->unique()->count());

        // What the reference decides stays: colours, sections and their order, header behaviour.
        $one = $looks[0];
        $this->assertSame('#ffcc00', $one['colors']['primary']);
        $this->assertSame(['banner', 'card_grid', 'faq'], array_column($one['pages']['home'], 'block'));
        $this->assertTrue($one['navigation']['mobile_bar']);
        $this->assertNotContains('minimal', $looks->pluck('nav')->all(), 'a header without buttons only for sites that show none');
    }

    public function test_a_new_site_takes_what_the_other_sites_use_least_and_keeps_it(): void
    {
        $first = SiteStyle::for(1)->apply(self::SAME_ANSWER);
        $second = SiteStyle::for(2, [$first])->apply(self::SAME_ANSWER);

        // Choices a page skeleton fixes may repeat across skeletons; every free one differs.
        $fixed = array_keys(config('theme.frames.'.$second['look']['frame'], []));
        foreach (array_diff_key($first['look'], array_flip($fixed)) as $key => $value) {
            $this->assertNotSame($value, $second['look'][$key] ?? null, "{$key}: two sites never share a choice while others are free");
        }

        // Generated again (other sites changed since): the site keeps its look.
        $again = SiteStyle::for(2, [$first, $second, $second], $second['look'])->apply(self::SAME_ANSWER);
        $this->assertSame($second['look'], $again['look']);
        $this->assertSame($second['nav'], $again['nav']);
    }

    public function test_each_site_gets_a_page_skeleton_that_shapes_its_header_hero_and_footer(): void
    {
        $frames = config('theme.frames');
        $sites = [];
        foreach (range(1, count($frames)) as $id) {
            $sites[] = SiteStyle::for($id, $sites)->apply(self::SAME_ANSWER);
        }
        $this->assertEqualsCanonicalizing(array_keys($frames), array_column(array_column($sites, 'look'), 'frame'), 'every skeleton is used before one repeats');

        foreach ($sites as $config) {
            $frame = $frames[$config['look']['frame']];
            if ($frame === []) {
                continue;
            }
            $this->assertFileExists(resource_path("theme/frames/{$config['look']['frame']}.css"));
            if (! Variants::decorSkin($config['style']['skin'])) {
                $this->assertSame('classic', $config['style']['skin'], 'the frame is the scheme');
            }
            $this->assertContains($config['nav'], $frame['nav']);
            $this->assertContains($config['footer'], $frame['footer']);
            foreach ($frame as $key => $allowed) {
                if (str_contains($key, '.')) {
                    $this->assertContains(data_get($config, $key), $allowed, "{$key}: the phone skeleton follows the frame");
                }
            }
            $this->assertStringContainsString(".fr-{$config['look']['frame']}", Variants::frameCss($config));
        }

        // A look kept from before skeletons existed stays frameless on a page redesign.
        $old = SiteStyle::for(1)->apply(self::SAME_ANSWER)['look'];
        unset($old['frame']);
        $this->assertSame('classic', SiteStyle::for(1, [], $old)->apply(self::SAME_ANSWER)['look']['frame']);
    }

    public function test_slots_systems_keep_their_skin_on_any_page_skeleton(): void
    {
        foreach (['luxe', 'vegas', 'dragon', 'candy', 'olympus', 'cyber', 'velvet', 'arcade', 'aztec', 'aurora'] as $i => $system) {
            $config = SiteStyle::for($i, [], ['system' => $system, 'frame' => 'jackpot'])->apply(self::SAME_ANSWER);
            $this->assertSame($system, $config['style']['skin'], 'a decorating skin goes with a frame');
            $css = Variants::frameCss($config);
            $this->assertStringContainsString(".st-skin-{$system}", $css);
            $this->assertLessThan(strpos($css, '.fr-jackpot'), strpos($css, ".st-skin-{$system}"), 'the frame comes last and wins ties');
        }
        // A skin that reshapes the page (bold, lobby…) still gives way to a frame.
        $this->assertSame('classic', SiteStyle::for(1, [], ['system' => 'lobby', 'frame' => 'jackpot'])->apply(self::SAME_ANSWER)['style']['skin']);
    }
}
