<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Upload disk for admin images and files: object storage (S3 / Aliyun OSS via
 * its S3-compatible API) when configured in 系统设置 › 对象存储, otherwise the
 * local public disk. Reads fall back to the public disk so files uploaded
 * before OSS was configured keep working.
 */
class Media
{
    public const DISK = 'media';

    public static function configured(): bool
    {
        return filled(Settings::get('oss_key')) && filled(Settings::get('oss_secret')) && filled(Settings::get('oss_bucket'));
    }

    /** Point the "media" disk at OSS or the public disk; called on boot and after settings change. */
    public static function configure(): void
    {
        try {
            $disk = self::configured() ? self::ossConfig() : config('filesystems.disks.public');
        } catch (Throwable) {
            $disk = config('filesystems.disks.public');
        }
        config(['filesystems.disks.'.self::DISK => $disk]);
        Storage::forgetDisk(self::DISK);
    }

    public static function ossConfig(): array
    {
        $driver = Settings::get('oss_driver') ?: 's3';
        $region = Settings::get('oss_region') ?: ($driver === 'oss' ? 'oss-cn-hangzhou' : 'us-east-1');
        $endpoint = Settings::get('oss_endpoint');
        if ($driver === 'oss') {
            // Aliyun accepts "cn-hangzhou" or "oss-cn-hangzhou"; its S3 API wants the bare region id.
            $region = preg_replace('/^oss-/', '', $region);
            $endpoint = $endpoint ?: "https://oss-{$region}.aliyuncs.com";
        }
        if ($endpoint && ! preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://'.$endpoint;
        }

        return array_filter([
            'driver' => 's3',
            'key' => Settings::get('oss_key'),
            'secret' => Settings::get('oss_secret'),
            'region' => $region,
            'bucket' => Settings::get('oss_bucket'),
            'endpoint' => $endpoint ?: null,
            'url' => Settings::get('oss_cdn_url') ?: null,
            // Aliyun OSS only supports virtual-hosted style; most S3 clones accept it too.
            'use_path_style_endpoint' => false,
            // Newer AWS SDKs add CRC checksums that S3-compatible services may reject.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ], fn ($v) => $v !== null);
    }

    public static function exists(?string $path): bool
    {
        return self::get($path) !== null;
    }

    public static function get(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        foreach ([self::DISK, 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($path)) {
                    return Storage::disk($disk)->get($path);
                }
            } catch (Throwable) {
                // OSS unreachable or misconfigured: try the next disk.
            }
        }

        return null;
    }

    /** A local file path for tools that need one (GD, pdftotext); remote files are copied to a temp file. */
    public static function localPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }
        $binary = self::get($path);
        if ($binary === null) {
            return null;
        }
        $tmp = storage_path('app/tmp/'.md5($path).'.'.(pathinfo($path, PATHINFO_EXTENSION) ?: 'bin'));
        @mkdir(dirname($tmp), 0775, true);
        file_put_contents($tmp, $binary);

        return $tmp;
    }

    /** Check credentials by writing and deleting a small object. Returns null on success, else the error. */
    public static function test(): ?string
    {
        try {
            $disk = Storage::build(self::ossConfig());
            $key = '.connection-test-'.uniqid();
            $disk->put($key, 'ok');
            $disk->delete($key);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
