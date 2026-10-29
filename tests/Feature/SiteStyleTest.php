<?php

namespace Tests\Feature;

use App\Services\Theme\SiteStyle;
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

        foreach ($first['look'] as $key => $value) {
            $this->assertNotSame($value, $second['look'][$key], "{$key}: two sites never share a choice while others are free");
        }
        $this->assertNotSame($first['navigation']['mobile_header'], $second['navigation']['mobile_header']);
        $this->assertNotSame($first['style']['mhero'], $second['style']['mhero']);

        // Generated again (other sites changed since): the site keeps its look.
        $again = SiteStyle::for(2, [$first, $second, $second], $second['look'])->apply(self::SAME_ANSWER);
        $this->assertSame($second['look'], $again['look']);
        $this->assertSame($second['nav'], $again['nav']);
    }
}
