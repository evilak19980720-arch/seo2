<?php

namespace Tests\Feature;

use App\Models\GenerationTask;
use App\Services\Content\LanguageDetector;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Render\ThemeStrings;
use App\Services\Sites\SiteGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SiteLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['fil', 'th'] as $lang) {
            File::delete(ThemeStrings::file($lang));
        }
        parent::tearDown();
    }

    public function test_detector_rejects_copy_in_another_language(): void
    {
        $english = str_repeat('Play the best slot games online with fast payouts and friendly support for every player. ', 3);
        $filipino = str_repeat('Maglaro ng mga pinakamahusay na laro at makakuha ng mga gantimpala sa bawat araw para sa lahat ng mga manlalaro. ', 3);
        $thai = 'เล่นเกมสล็อตออนไลน์ที่ดีที่สุด พร้อมโบนัสต้อนรับสำหรับสมาชิกใหม่ทุกคน';
        $portuguese = str_repeat('Jogue os melhores jogos de slot online com pagamentos rápidos e suporte para todos os jogadores. ', 3);

        $this->assertFalse(LanguageDetector::matches($english, 'fil'));
        $this->assertTrue(LanguageDetector::matches($filipino, 'fil'));
        $this->assertTrue(LanguageDetector::matches($filipino, 'tl'));
        $this->assertFalse(LanguageDetector::matches($english, 'th'));
        $this->assertTrue(LanguageDetector::matches($thai.' Fortune Tiger', 'th'));
        $this->assertFalse(LanguageDetector::matches($thai, 'pt'));
        $this->assertFalse(LanguageDetector::matches($portuguese, 'es'));
        $this->assertTrue(LanguageDetector::matches($portuguese, 'pt'));
        $this->assertTrue(LanguageDetector::matches('Aviator', 'ja'), 'names alone pass');
    }

    public function test_site_language_without_lang_file_gets_translated_interface(): void
    {
        $this->fakeAi(['pages' => ['guide' => ['title' => 'Como jogar', 'blocks' => ['prose' => ['html' => '<p>'.str_repeat('Neste guia você aprende como o jogo funciona e quais botões usar para desviar dos asteroides. ', 12).'</p>']]]]]);
        $site = $this->makeSite(['domain' => 'galaxy-run.ph', 'locale' => 'fil-PH', 'target_country' => 'PH']);
        $this->assertStringStartsWith('Filipino', $site->languageName());

        app(SiteGenerator::class)->run(GenerationTask::create(['site_id' => $site->id, 'type' => 'full']));

        $stored = json_decode(file_get_contents(ThemeStrings::file('fil')), true);
        $this->assertSame('Isalin Home', $stored['strings']['page_titles.home']);
        $this->assertSame(count(ThemeStrings::source()), count($stored['strings']));

        $site->refresh();
        $home = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertStringContainsString('<html lang="fil-PH">', $home);
        $this->assertStringContainsString('Isalin ', $home, 'menus and labels use the translated strings');

        // The fake AI writes the pages in Portuguese: flagged, retried and kept out of the index.
        $this->assertTrue((bool) $site->pages()->where('type', 'guide')->value('noindex'));
    }

    public function test_page_in_wrong_language_is_reported(): void
    {
        $site = $this->makeSite(['locale' => 'th-TH', 'target_country' => 'TH']);
        $page = ['blocks' => ['prose' => ['html' => '<p>'.str_repeat('In this guide you will learn how the game works, which buttons to use and how to set your own limits before you play. ', 30).'</p>']]];

        $problems = app(SiteGenerator::class)->pageProblems($site, ['guide' => $page], '');

        $this->assertStringContainsString('Not written in Thai', $problems['guide'] ?? '');
    }
}
