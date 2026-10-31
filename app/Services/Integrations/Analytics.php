<?php

namespace App\Services\Integrations;

/** GA4 Data API (runReport) with the same service account. */
class Analytics
{
    /** @return array<string, array{users: int, sessions: int, pageviews: int}> keyed by Y-m-d */
    public function daily(string $propertyId, string $start, string $end): array
    {
        $res = GoogleAuth::http()->post("https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport", [
            'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
            'dimensions' => [['name' => 'date']],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
        ]);
        if (! $res->successful()) {
            throw new IntegrationException('GA4 runReport failed: '.$res->body());
        }

        $out = [];
        foreach ($res->json('rows') ?? [] as $row) {
            $d = $row['dimensionValues'][0]['value'];
            $date = substr($d, 0, 4).'-'.substr($d, 4, 2).'-'.substr($d, 6, 2);
            $out[$date] = [
                'users' => (int) $row['metricValues'][0]['value'],
                'sessions' => (int) $row['metricValues'][1]['value'],
                'pageviews' => (int) $row['metricValues'][2]['value'],
            ];
        }

        return $out;
    }
}
