<?php

namespace App\Services\Integrations;

use App\Support\Binary;
use Symfony\Component\Process\Process;

/** Headless Chromium screenshots of a reference site (desktop + mobile). */
class Screenshot
{
    /** @return array<int, string> data URIs (PNG) */
    public function capture(string $url): array
    {
        $bin = Binary::find(config('platform.chromium_bin'));
        if (! $bin || ! preg_match('#^https?://#i', $url)) {
            return [];
        }
        $shots = [];
        foreach (['desktop' => '1366,900', 'mobile' => '412,915'] as $size) {
            $file = tempnam(sys_get_temp_dir(), 'shot').'.png';
            $p = new Process([
                $bin, '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
                '--window-size='.$size, '--virtual-time-budget=8000', '--screenshot='.$file, $url,
            ], null, null, null, 60);
            $p->run();
            if (is_file($file) && filesize($file) > 0) {
                $shots[] = 'data:image/png;base64,'.base64_encode(file_get_contents($file));
            }
            @unlink($file);
        }

        return $shots;
    }
}
