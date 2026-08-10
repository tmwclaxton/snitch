<?php

namespace Tests\Unit\Support;

use App\Support\PublicDiskMedia;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDiskMediaTest extends TestCase
{
    public function test_relative_path_from_storage_url(): void
    {
        $this->assertSame(
            'youtube-media/abc.mp4',
            PublicDiskMedia::relativePathFromUrl('http://localhost:8000/storage/youtube-media/abc.mp4'),
        );
        $this->assertNull(PublicDiskMedia::relativePathFromUrl('https://cdn.example.com/clip.mp4'));
    }

    public function test_exists_on_public_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('youtube-media/abc.mp4', 'bytes');

        $this->assertTrue(PublicDiskMedia::existsOnPublicDisk(
            'http://localhost:8000/storage/youtube-media/abc.mp4',
        ));
        $this->assertFalse(PublicDiskMedia::existsOnPublicDisk(
            'http://localhost:8000/storage/youtube-media/missing.mp4',
        ));
    }

    public function test_analyzable_url_inlines_loopback_public_disk_media(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('youtube-media/abc.mp4', 'hello-mp4');

        $url = PublicDiskMedia::analyzableUrl('http://localhost:8000/storage/youtube-media/abc.mp4');

        $this->assertSame('data:video/mp4;base64,'.base64_encode('hello-mp4'), $url);
    }

    public function test_analyzable_url_keeps_public_hosts(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('youtube-media/abc.mp4', 'hello-mp4');

        $url = 'https://www.snitchsocial.net/storage/youtube-media/abc.mp4';

        $this->assertSame($url, PublicDiskMedia::analyzableUrl($url));
    }
}
