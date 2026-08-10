<?php

namespace Tests\Unit\Support;

use App\Support\PublicDiskMedia;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function test_estimated_data_uri_bytes_accounts_for_base64_expansion(): void
    {
        $this->assertSame(64, PublicDiskMedia::estimatedDataUriBytes(0));
        $this->assertGreaterThan(100, PublicDiskMedia::estimatedDataUriBytes(100));
    }

    public function test_oversized_loopback_media_without_ffmpeg_throws(): void
    {
        // Use a real temp disk so Storage::path() works for the compress path.
        $root = storage_path('framework/testing/disks/public-media-'.uniqid());
        config(['filesystems.disks.public.root' => $root]);
        config(['snitch.video_analysis.max_inline_data_uri_bytes' => 200]);
        config(['snitch.video_analysis.ffmpeg_binary' => '/nonexistent-ffmpeg-binary']);
        Storage::forgetDisk('public');

        if (! is_dir($root.'/youtube-media')) {
            mkdir($root.'/youtube-media', 0777, true);
        }

        file_put_contents($root.'/youtube-media/big.mp4', str_repeat('x', 500));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ffmpeg is required');

            PublicDiskMedia::analyzableUrl('http://localhost:8000/storage/youtube-media/big.mp4');
        } finally {
            @unlink($root.'/youtube-media/big.mp4');
            @rmdir($root.'/youtube-media');
            @rmdir($root);
        }
    }

    public function test_oversized_loopback_media_is_compressed_when_ffmpeg_available(): void
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));

        if ($ffmpeg === '') {
            $this->markTestSkipped('ffmpeg is not available');
        }

        $root = storage_path('framework/testing/disks/public-media-'.uniqid());
        config(['filesystems.disks.public.root' => $root]);
        config(['snitch.video_analysis.ffmpeg_binary' => $ffmpeg]);
        Storage::forgetDisk('public');

        if (! is_dir($root.'/youtube-media')) {
            mkdir($root.'/youtube-media', 0777, true);
        }

        $source = $root.'/youtube-media/long.mp4';
        $generate = trim((string) shell_exec(
            escapeshellarg($ffmpeg).
            ' -y -f lavfi -i testsrc=size=640x360:rate=30 -f lavfi -i sine=frequency=440'.
            ' -t 6 -c:v libx264 -pix_fmt yuv420p -crf 18 -c:a aac -shortest '.
            escapeshellarg($source).' 2>&1',
        ));

        if (! is_file($source) || filesize($source) < 20_000) {
            @unlink($source);
            @rmdir($root.'/youtube-media');
            @rmdir($root);
            $this->markTestSkipped('Unable to generate oversized test mp4: '.$generate);
        }

        // Force the compress path with a limit just under the original data-URI size.
        $originalEstimate = PublicDiskMedia::estimatedDataUriBytes((int) filesize($source));
        $limit = max(8_000, $originalEstimate - 1_024);
        config(['snitch.video_analysis.max_inline_data_uri_bytes' => $limit]);

        try {
            $url = PublicDiskMedia::analyzableUrl('http://localhost:8000/storage/youtube-media/long.mp4');

            $this->assertStringStartsWith('data:video/mp4;base64,', $url);
            $this->assertLessThanOrEqual(
                PublicDiskMedia::maxInlineDataUriBytes(),
                strlen($url),
            );
            $this->assertLessThan(
                $originalEstimate,
                strlen($url),
                'Expected ffmpeg-compressed payload smaller than the original estimate.',
            );
        } finally {
            @unlink($source);
            @rmdir($root.'/youtube-media');
            @rmdir($root);
        }
    }
}
