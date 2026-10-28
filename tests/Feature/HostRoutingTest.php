<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\AdStat;
use App\Models\Domain;
use App\Models\Site;
use App\Models\User;
use App\Services\Render\StaticSiteBuilder;
use App\Services\Sites\SiteGenerator;
use App\Services\Sites\SiteManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_domains_never_expose_admin_or_installer(): void
    {
        $site = $this->makeSite(['domain' => 'marca.com.br', 'status' => 'live']);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site);

        $this->get('https://marca.com.br/admin')->assertNotFound();
        $this->get('https://marca.com.br/admin/login')->assertNotFound();
        $this->get('https://marca.com.br/install')->assertNotFound();
        $this->get('https://marca.com.br/')->assertOk()->assertSee('Galaxy Run');
        $this->get('https://www.marca.com.br/x')->assertRedirect('https://marca.com.br/x');
        $this->get('https://marca.com.br/_theme/base.css?v=1')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('https://marca.com.br/_theme/../.env')->assertNotFound();
    }

    public function test_admin_domain_serves_panel_and_hides_installer_once_installed(): void
    {
        $this->get('https://admin.test/admin/login')->assertOk();
        $this->get('https://admin.test/install')->assertNotFound();
    }

    public function test_admin_panel_reachable_by_server_ip_after_install(): void
    {
        $this->get('http://203.0.113.10/admin/login')->assertOk();
        $this->get('http://203.0.113.10/install')->assertNotFound();
    }

    public function test_session_cookie_is_secure_only_over_https(): void
    {
        config(['session.secure' => true]);

        $http = $this->get('http://admin.test/admin/login')->assertOk();
        $this->assertFalse(collect($http->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'))->isSecure());

        $https = $this->get('https://admin.test/admin/login')->assertOk();
        $this->assertTrue(collect($https->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'))->isSecure());
    }

    public function test_installer_only_reachable_before_install_from_trusted_host(): void
    {
        unlink(config('platform.installed_lock'));

        $this->get('https://admin.test/admin')->assertRedirect('/install');
        $this->get('https://admin.test/install')->assertOk()->assertSee('环境检测');
        $this->get('https://random-brand.com/install')->assertNotFound();
    }

    public function test_deleting_site_serves_410_and_frees_domain(): void
    {
        $site = $this->makeSite(['domain' => 'velho.com.br', 'status' => 'live']);
        app(StaticSiteBuilder::class)->buildAll($site);

        app(SiteManager::class)->delete($site);

        $this->get('https://velho.com.br/')->assertStatus(410);
        $this->assertTrue(Domain::available()->whereKey($site->domain_id)->exists());
        $this->assertNull(Site::withTrashed()->find($site->id));
    }

    public function test_preview_requires_login(): void
    {
        $site = $this->makeSite(['status' => 'preview']);
        app(SiteGenerator::class)->generatePages($site);
        app(StaticSiteBuilder::class)->buildAll($site);

        $this->get("https://admin.test/preview/{$site->id}/")->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get("https://admin.test/preview/{$site->id}/")->assertOk()->assertSee('/preview/'.$site->id.'/', false);
    }

    public function test_ad_beacon_counts_only_bound_ads(): void
    {
        $site = $this->makeSite(['domain' => 'ads.com.br', 'status' => 'live']);
        $ad = Ad::create(['name' => 'x', 'code' => '<script></script>', 'position' => 'top', 'desktop_height' => 90, 'mobile_height' => 100]);

        $this->call('POST', 'https://ads.com.br/_t/ad', [], [], [], [], "ad={$ad->id}&site={$site->id}&e=imp")->assertNoContent();
        $this->assertSame(0, (int) AdStat::sum('impressions'));

        $ad->sites()->attach($site);
        $this->call('POST', 'https://ads.com.br/_t/ad', [], [], [], [], "ad={$ad->id}&site={$site->id}&e=imp")->assertNoContent();
        $this->assertSame(1, (int) AdStat::sum('impressions'));
    }
}
