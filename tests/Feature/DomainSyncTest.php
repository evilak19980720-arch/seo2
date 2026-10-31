<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Services\Domains\DomainSync;
use App\Services\Integrations\Cloudflare;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainSyncTest extends TestCase
{
    use RefreshDatabase;

    private function sync(?string $account = null): DomainSync
    {
        return new DomainSync(new Cloudflare('token', $account));
    }

    public function test_pull_mirrors_cloudflare_zones_across_pages(): void
    {
        Http::fake(['api.cloudflare.com/client/v4/zones*' => fn (Request $r) => Http::response([
            'success' => true,
            'result' => $r['page'] == 1
                ? [['id' => 'z1', 'name' => 'Alpha.com', 'status' => 'active', 'name_servers' => ['a.ns.cloudflare.com']]]
                : [['id' => 'z2', 'name' => 'beta.com.br', 'status' => 'pending', 'name_servers' => ['b.ns.cloudflare.com']]],
            'result_info' => ['total_pages' => 2],
        ])]);
        Domain::create(['name' => 'gone.com', 'cf_zone_id' => 'old', 'cf_status' => 'active']);

        $result = $this->sync()->pull();

        $this->assertSame(['added' => 2, 'updated' => 0, 'missing' => 1], $result);
        $this->assertSame('pending', Domain::where('name', 'beta.com.br')->value('cf_status'));
        $this->assertTrue(Domain::where('name', 'alpha.com')->exists());
        $this->assertSame('missing', Domain::where('name', 'gone.com')->value('cf_status'));
    }

    public function test_only_unbound_cloudflare_domains_are_available_for_new_sites(): void
    {
        $site = $this->makeSite(['domain' => 'bound.com']);
        Domain::create(['name' => 'free.com', 'cf_zone_id' => 'z1', 'cf_status' => 'active']);
        Domain::create(['name' => 'pending.com', 'cf_zone_id' => 'z2', 'cf_status' => 'pending']);
        Domain::create(['name' => 'removed.com', 'cf_zone_id' => 'z3', 'cf_status' => 'missing']);
        Domain::create(['name' => 'local-only.com']);

        $this->assertSame(['free.com', 'pending.com'], Domain::available()->orderBy('name')->pluck('name')->all());
        $this->assertNotContains($site->domain->name, Domain::available()->pluck('name'));
    }

    public function test_add_creates_zone_and_requires_account_id(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'z9', 'name' => 'novo.com', 'status' => 'pending', 'name_servers' => ['x.ns.cloudflare.com']]])]);

        $domain = $this->sync('acc')->add('https://www.Novo.com/');

        $this->assertSame(['novo.com', 'z9', 'pending'], [$domain->name, $domain->cf_zone_id, $domain->cf_status]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['account'] === ['id' => 'acc']);

        $this->expectExceptionMessage('Account ID');
        $this->sync()->add('outro.com');
    }

    public function test_bound_domain_cannot_be_removed(): void
    {
        $site = $this->makeSite(['domain' => 'bound.com']);

        $this->expectExceptionMessage('已绑定网站');
        $this->sync()->remove($site->domain);
    }

    public function test_remove_can_delete_the_zone_too(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['id' => 'z1']])]);
        $domain = Domain::create(['name' => 'free.com', 'cf_zone_id' => 'z1', 'cf_status' => 'active']);

        $this->sync()->remove($domain, deleteZone: true);

        $this->assertModelMissing($domain);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/zones/z1'));
    }

    public function test_dns_host_names_resolve_against_the_domain(): void
    {
        $domain = new Domain(['name' => 'jogo.com']);

        $this->assertSame('jogo.com', $domain->fqdn('@'));
        $this->assertSame('www.jogo.com', $domain->fqdn('www'));
        $this->assertSame('api.jogo.com', $domain->fqdn('api.jogo.com'));
    }

    public function test_choosing_a_domain_for_a_site_points_apex_and_www_here(): void
    {
        Settings::setMany(['cloudflare_api_token' => 't', 'server_ip' => '203.0.113.7']);
        config(['platform.certs_path' => $this->tmp.'/certs']);
        Http::fake([
            'api.cloudflare.com/client/v4/zones/*/dns_records*' => fn (Request $r) => Http::response(['success' => true, 'result' => $r->method() === 'GET' ? [] : ['id' => 'r1']]),
            'api.cloudflare.com/client/v4/certificates' => Http::response(['success' => true, 'result' => ['certificate' => 'CERT', 'expires_on' => '2041-10-25 16:44:00 +0000 UTC']]),
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []]),
        ]);

        $site = $this->makeSite(['domain' => 'novo.com']);

        $records = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'dns_records'));
        $this->assertSame([['A', 'novo.com', '203.0.113.7'], ['CNAME', 'www.novo.com', 'novo.com']], $records->map(fn ($r) => [$r['type'], $r['name'], $r['content']])->values()->all());
        $domain = $site->domain->fresh();
        $this->assertSame(2041, $domain->certificate_expires_at->year);
        $this->assertNull($domain->last_error);
    }

    public function test_a_failed_save_records_only_the_error(): void
    {
        $domain = Domain::create(['name' => 'x.com']);
        $domain->ip = 'not saved';
        $domain->recordError('boom');

        $fresh = $domain->fresh();
        $this->assertSame('boom', $fresh->last_error);
        $this->assertNull($fresh->ip);
    }
}
