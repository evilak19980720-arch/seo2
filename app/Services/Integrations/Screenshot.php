<?php

namespace App\Services\Integrations;

use App\Support\Chromium;

/** Headless Chromium screenshots of a reference site (desktop + mobile). */
class Screenshot
{
    /** @var array<int, string> why captures failed */
    public array $errors = [];

    /** @return array<int, string> data URIs (PNG) */
    public function capture(string $url): array
    {
        $this->errors = [];
        if (! Chromium::bin() || ! preg_match('#^https?://#i', $url)) {
            return [];
        }
        $shots = [];
        foreach (['desktop' => '1366,2400', 'mobile' => '412,1800'] as $size) {
            $file = sys_get_temp_dir().'/shot-'.bin2hex(random_bytes(6)).'.png';
            Chromium::run(['--window-size='.$size, '--virtual-time-budget=10000', '--screenshot='.$file, $url], 45, function () use ($file) {
                clearstatcache(true, $file);

                return is_file($file) && filesize($file) > 0;
            });
            if (is_file($file) && filesize($file) > 0) {
                $shots[] = 'data:image/png;base64,'.base64_encode(file_get_contents($file));
            } elseif (Chromium::$lastError !== '') {
                $this->errors[] = Chromium::$lastError;
            }
            @unlink($file);
        }

        return $shots;
    }
}
