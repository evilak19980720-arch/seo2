<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Services\Render\StaticSiteBuilder;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOssPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function siteWithImage(): Site
    {
        Storage::fake('public');
        $img = imagecreatetruecolor(800, 450);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 90, 200));
        ob_start();
        imagepng($img);
        Storage::disk('public')->put('brand/shot.png', ob_get_clean());

        $site = $this->makeSite(['domain' => 'oss-'.uniqid().'.com.br'], ['media' => [['type' => 'image', 'path' => 'brand/shot.png', 'alt' => 'Tela']]]);
        $site->pages()->create(['type' => 'home', 'slug' => '', 'title' => 'Galaxy Run', 'content' => []]);

        return $site->fresh(['domain', 'brandProfile']);
    }

    public function test_published_images_are_served_from_object_storage(): void
    {
        Settings::setMany(['oss_key' => 'k', 'oss_secret' => 's', 'oss_bucket' => 'b']);
        Storage::fake('media', ['url' => 'https://cdn.test']);
        Http::fake(['cdn.test/*' => Http::response('', 200)]);
        $site = $this->siteWithImage();

        (new StaticSiteBuilder)->buildAll($site);

        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertStringContainsString('https://cdn.test/sites/'.$site->domain->name.'/media/', $html);
        $this->assertNotEmpty(Storage::disk('media')->files('sites/'.$site->domain->name.'/media'));

        // A second build reuses the uploads without re-sending them.
        Storage::disk('media')->deleteDirectory('sites');
        (new StaticSiteBuilder)->buildAll($site);
        $this->assertSame([], Storage::disk('media')->allFiles('sites'));
        $this->assertStringContainsString('https://cdn.test/sites/', file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html'));
    }

    public function test_images_stay_on_the_site_when_the_bucket_is_not_public(): void
    {
        Settings::setMany(['oss_key' => 'k', 'oss_secret' => 's', 'oss_bucket' => 'b']);
        Storage::fake('media', ['url' => 'https://cdn.test']);
        Http::fake(['cdn.test/*' => Http::response('denied', 403)]);
        $site = $this->siteWithImage();

        (new StaticSiteBuilder)->buildAll($site);

        $html = file_get_contents(StaticSiteBuilder::dirFor($site).'/index.html');
        $this->assertStringNotContainsString('cdn.test', $html);
        $this->assertStringContainsString('/media/', $html);
    }
}
