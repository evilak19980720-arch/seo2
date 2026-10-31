<?php

namespace Tests\Unit;

use App\Services\Integrations\Cloudflare;
use App\Services\Integrations\IntegrationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareTokenTest extends TestCase
{
    public function test_pasted_token_is_cleaned_before_sending(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['status' => 'active']])]);

        $this->assertSame('active', (new Cloudflare("  Bearer abc-DEF_123\n"))->verifyToken());
        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Bearer abc-DEF_123']);
    }

    public function test_account_token_falls_back_to_account_endpoint(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 401),
            'api.cloudflare.com/client/v4/accounts/acc1/tokens/verify' => Http::response(['success' => true, 'result' => ['status' => 'active']]),
        ]);

        $this->assertSame('active', (new Cloudflare('cfat_token', 'acc1'))->verifyToken());
    }

    public function test_global_api_key_is_rejected_with_a_clear_message(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Global API Key');

        (new Cloudflare(str_repeat('a', 37)))->verifyToken();
    }
}
