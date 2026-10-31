<?php

namespace App\Http\Controllers;

use App\Models\AdStat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Beacon endpoint for ad impressions and (estimated) clicks. */
class AdTrackController
{
    public function __invoke(Request $request)
    {
        parse_str($request->getContent(), $body);
        $adId = (int) ($body['ad'] ?? $request->input('ad'));
        $siteId = (int) ($body['site'] ?? $request->input('site'));
        $event = ($body['e'] ?? $request->input('e')) === 'click' ? 'estimated_clicks' : 'impressions';

        $key = 'ad-beacon:'.$request->ip().':'.$adId.':'.$event;
        if (! $adId || ! $siteId || RateLimiter::tooManyAttempts($key, $event === 'impressions' ? 60 : 10)) {
            return response()->noContent();
        }
        RateLimiter::hit($key, 3600);

        if (! DB::table('ad_site')->where(['ad_id' => $adId, 'site_id' => $siteId])->exists()) {
            return response()->noContent();
        }

        $stat = AdStat::firstOrCreate(['ad_id' => $adId, 'site_id' => $siteId, 'date' => now()->toDateString()]);
        $stat->increment($event);

        return response()->noContent();
    }
}
