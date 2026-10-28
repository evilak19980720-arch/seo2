<?php

namespace Tests\Unit;

use App\Services\Integrations\Cloudflare;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareDnsTest extends TestCase
{
    private function fakeZone(array $records): void
    {
        Http::fake(function (Request $r) use ($records) {
            return $r->method() === 'GET'
                ? Http::response(['success' => true, 'result' => $records, 'result_info' => ['total_pages' => 1]])
                : Http::response(['success' => true, 'result' => ['id' => 'new']]);
        });
    }

    public function test_pointing_apex_replaces_old_address_records(): void
    {
        $this->fakeZone([
            ['id' => 'old-a', 'type' => 'A', 'name' => 'ai-token.sbs', 'content' => '52.199.249.206'],
            ['id' => 'old-a2', 'type' => 'A', 'name' => 'ai-token.sbs', 'content' => '1.1.1.1'],
            ['id' => 'old-aaaa', 'type' => 'AAAA', 'name' => 'ai-token.sbs', 'content' => '::1'],
            ['id' => 'mx', 'type' => 'MX', 'name' => 'ai-token.sbs', 'content' => 'mail.x'],
            ['id' => 'www', 'type' => 'CNAME', 'name' => 'www.ai-token.sbs', 'content' => 'ai-token.sbs'],
        ]);

        (new Cloudflare('t'))->upsertRecord('z', 'A', 'ai-token.sbs', '43.107.44.216', proxied: true);

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method().' '.parse_url($pair[0]->url(), PHP_URL_PATH));
        $this->assertContains('PUT /client/v4/zones/z/dns_records/old-a', $sent);
        $this->assertContains('DELETE /client/v4/zones/z/dns_records/old-a2', $sent);
        $this->assertContains('DELETE /client/v4/zones/z/dns_records/old-aaaa', $sent);
        $this->assertNotContains('DELETE /client/v4/zones/z/dns_records/mx', $sent);
        $this->assertNotContains('DELETE /client/v4/zones/z/dns_records/www', $sent);
        $this->assertFalse($sent->contains(fn ($s) => str_starts_with($s, 'POST')));
    }

    public function test_cname_replaces_a_record_on_same_name(): void
    {
        $this->fakeZone([['id' => 'old-a', 'type' => 'A', 'name' => 'www.jogo.com', 'content' => '9.9.9.9']]);

        (new Cloudflare('t'))->upsertRecord('z', 'CNAME', 'www.jogo.com', 'jogo.com', proxied: true);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/old-a'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'CNAME');
    }

    public function test_existing_txt_value_is_not_duplicated(): void
    {
        $this->fakeZone([['id' => 't1', 'type' => 'TXT', 'name' => 'jogo.com', 'content' => '"google-site-verification=abc"']]);

        (new Cloudflare('t'))->upsertRecord('z', 'TXT', 'jogo.com', 'google-site-verification=abc');

        Http::assertSentCount(1);
    }
}
