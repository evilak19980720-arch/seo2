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
}
