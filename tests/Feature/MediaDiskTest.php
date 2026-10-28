<?php

namespace Tests\Feature;

use App\Support\Media;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploads_use_local_disk_without_oss(): void
    {
        Media::configure();

        $this->assertSame('local', config('filesystems.disks.media.driver'));
    }

    public function test_uploads_go_to_aliyun_oss_when_configured(): void
    {
        Settings::setMany(['oss_driver' => 'oss', 'oss_key' => 'k', 'oss_secret' => 's', 'oss_bucket' => 'brand-img', 'oss_region' => 'oss-cn-shenzhen']);
        Media::configure();

        $disk = config('filesystems.disks.media');
        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('cn-shenzhen', $disk['region']);
        $this->assertSame('https://oss-cn-shenzhen.aliyuncs.com', $disk['endpoint']);
        $this->assertSame('brand-img', $disk['bucket']);
    }

    public function test_reads_fall_back_to_files_uploaded_before_oss(): void
    {
        Storage::fake('public');
        Storage::fake('media');
        Storage::disk('public')->put('logos/old.png', 'old');
        Storage::disk('media')->put('logos/new.png', 'new');

        $this->assertSame('old', Media::get('logos/old.png'));
        $this->assertSame('new', Media::get('logos/new.png'));
        $this->assertNull(Media::get('logos/missing.png'));
        $this->assertFileExists(Media::localPath('logos/old.png'));
    }

    public function test_aliyun_endpoint_pasted_into_region_field_is_understood(): void
    {
        Settings::setMany(['oss_driver' => 'oss', 'oss_key' => 'k', 'oss_secret' => 's', 'oss_bucket' => 'b', 'oss_region' => 'oss-ap-northeast-2.aliyuncs.com ', 'oss_endpoint' => null]);
        Media::configure();

        $this->assertSame('ap-northeast-2', config('filesystems.disks.media.region'));
        $this->assertSame('https://oss-ap-northeast-2.aliyuncs.com', config('filesystems.disks.media.endpoint'));
    }

    public function test_broken_endpoint_falls_back_to_local_disk(): void
    {
        Settings::setMany(['oss_driver' => 's3', 'oss_key' => 'k', 'oss_secret' => 's', 'oss_bucket' => 'b', 'oss_endpoint' => 'oss..example']);
        Media::configure();

        $this->assertSame('local', config('filesystems.disks.media.driver'));
    }
}
