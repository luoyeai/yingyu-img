<?php

namespace Tests\Unit;

use App\Services\ImageProcessor;
use App\Services\ImageStorage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tests\TestCase;

class ImageProcessorTest extends TestCase
{
    private string $temporaryDirectory;

    private ImageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(extension_loaded('imagick'), 'Imagick must be loaded; use bin/test on this local environment.');
        $this->temporaryDirectory = sys_get_temp_dir().'/vault-processor-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
        $this->app->useStoragePath($this->temporaryDirectory.'/storage');
        $this->processor = new ImageProcessor;
    }

    protected function tearDown(): void
    {
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_converts_jpeg_to_valid_webp_and_limits_long_edge_without_upscaling(): void
    {
        $file = $this->image('jpeg', 320, 180);
        $result = $this->processor->process($file, ['max_edge' => 160]);
        $this->assertSame([160, 90], [$result['width'], $result['height']]);
        $this->assertSame([320, 180], [$result['source_width'], $result['source_height']]);
        $this->assertSame('image/webp', getimagesize($result['temp_path'])['mime']);
        $this->assertSame(80, $result['processing']['quality']);
        $larger = $this->processor->process($file, ['max_edge' => 640]);
        $this->assertSame([320, 180], [$larger['width'], $larger['height']]);
    }

    public function test_png_transparency_survives_lossless_conversion(): void
    {
        $result = $this->processor->process($this->image('png', 80, 50, 'transparent'), ['lossless' => true]);
        $image = new \Imagick($result['temp_path']);
        $this->assertSame(0.0, $image->getImagePixelColor(0, 0)->getColorValue(\Imagick::COLOR_ALPHA));
        $this->assertTrue($result['processing']['lossless']);
        $image->clear();
    }

    public function test_jpeg_exif_orientation_is_corrected_and_metadata_is_removed(): void
    {
        $file = $this->image('jpeg', 40, 20);
        $data = file_get_contents($file->getPathname());
        $exif = "Exif\0\0II".pack('v', 42).pack('V', 8).pack('v', 1).pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', 6)."\0\0".pack('V', 0);
        file_put_contents($file->getPathname(), substr($data, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($data, 2));
        $result = $this->processor->process($file, []);
        $this->assertSame([20, 40], [$result['width'], $result['height']]);
        $image = new \Imagick($result['temp_path']);
        $this->assertSame([], $image->getImageProfiles());
        $image->clear();
    }

    public function test_existing_webp_is_not_reencoded_when_no_transform_is_needed(): void
    {
        $file = $this->image('webp', 80, 60);
        $result = $this->processor->process($file, ['quality' => 40]);
        $this->assertTrue($result['processing']['reused_webp']);
        $this->assertSame(file_get_contents($file->getPathname()), file_get_contents($result['temp_path']));
    }

    public function test_webp_metadata_is_removed_without_changing_compressed_image_payload(): void
    {
        $file = $this->image('webp', 80, 60);
        $original = file_get_contents($file->getPathname());
        $chunk = 'XMP '.pack('V', 6).'secret';
        $withMetadata = substr($original, 0, 4).pack('V', strlen($original) - 8 + strlen($chunk)).substr($original, 8).$chunk;
        file_put_contents($file->getPathname(), $withMetadata);
        $result = $this->processor->process($file, []);
        $this->assertSame($original, file_get_contents($result['temp_path']));
    }

    public function test_dynamic_webp_is_rejected_with_clear_message(): void
    {
        $file = $this->image('webp', 30, 30);
        $data = file_get_contents($file->getPathname());
        $animation = 'ANIM'.pack('V', 6).str_repeat("\0", 6);
        file_put_contents($file->getPathname(), substr($data, 0, 4).pack('V', strlen($data) - 8 + strlen($animation)).substr($data, 8).$animation);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('动态 WebP');
        $this->processor->process($file, []);
    }

    public function test_apng_control_chunk_is_rejected_even_if_decoder_only_sees_first_frame(): void
    {
        $file = $this->image('png', 40, 40);
        $data = file_get_contents($file->getPathname());
        $payload = pack('N2', 2, 0);
        $chunk = pack('N', strlen($payload)).'acTL'.$payload.pack('N', crc32('acTL'.$payload));
        file_put_contents($file->getPathname(), substr($data, 0, 33).$chunk.substr($data, 33));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('动态 PNG');
        $this->processor->process($file, []);
    }

    public function test_png_metadata_text_is_not_mistaken_for_animation_chunks(): void
    {
        $file = $this->image('png', 40, 40);
        $data = file_get_contents($file->getPathname());
        $payload = "Comment\0acTL fcTL fdAT";
        $chunk = pack('N', strlen($payload)).'tEXt'.$payload.pack('N', crc32('tEXt'.$payload));
        file_put_contents($file->getPathname(), substr($data, 0, 33).$chunk.substr($data, 33));
        $result = $this->processor->process($file, []);
        $this->assertSame([40, 40], [$result['width'], $result['height']]);
    }

    public function test_truncated_png_is_rejected_before_processing(): void
    {
        $file = $this->image('png', 40, 40);
        file_put_contents($file->getPathname(), substr(file_get_contents($file->getPathname()), 0, -7));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PNG 文件');
        $this->processor->process($file, []);
    }

    public function test_gif_and_svg_are_rejected_even_when_filename_claims_jpeg(): void
    {
        foreach (['GIF89a'.str_repeat("\0", 30), '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>'] as $index => $content) {
            $path = $this->temporaryDirectory.'/bad-'.$index;
            file_put_contents($path, $content);
            try {
                $this->processor->process(new UploadedFile($path, 'fake.jpg', test: true), []);
                $this->fail('Unsupported format was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('仅支持 JPEG', $exception->getMessage());
            }
        }
    }

    public function test_pixel_limit_is_checked_before_decode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('像素总数');
        $this->processor->process($this->image('png', 100, 100), ['max_pixels' => 9999]);
    }

    public function test_invalid_webp_container_is_rejected(): void
    {
        $file = $this->image('webp', 40, 40);
        file_put_contents($file->getPathname(), file_get_contents($file->getPathname()).'trailing');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('结构无效');
        $this->processor->process($file, []);
    }

    public function test_fingerprint_is_stable_but_separates_processing_and_retention_settings(): void
    {
        $file = $this->image('jpeg', 40, 40);
        $first = $this->processor->process($file, []);
        $same = $this->processor->process($file, ['quality' => '80', 'max_pixels' => 500000]);
        $differentQuality = $this->processor->process($file, ['quality' => 90]);
        $retainOriginal = $this->processor->process($file, ['keep_original' => true]);
        $this->assertSame($first['fingerprint'], $same['fingerprint']);
        $this->assertNotSame($first['fingerprint'], $differentQuality['fingerprint']);
        $this->assertNotSame($first['fingerprint'], $retainOriginal['fingerprint']);
        $this->processor->cleanup($first);
        $this->assertFileDoesNotExist($first['temp_path']);
        $this->assertFileDoesNotExist($first['preview_temp_path']);
    }

    public function test_cleanup_cannot_delete_files_outside_the_processing_directory(): void
    {
        $outside = $this->temporaryDirectory.'/keep.webp';
        file_put_contents($outside, 'keep');
        $this->processor->cleanup(['temp_path' => $outside, 'preview_temp_path' => $outside]);
        $this->assertFileExists($outside);
    }

    public function test_storage_rejects_traversal_absolute_paths_and_executable_extensions(): void
    {
        $storage = $this->app->make(ImageStorage::class);
        foreach (['../secret.webp', '/2026/10/'.str_repeat('a', 32).'.webp', '2026/13/'.str_repeat('a', 32).'.webp', '2026/10/'.str_repeat('a', 32).'.php', '2026/10/'.str_repeat('a', 32).'.webp/../file'] as $path) {
            try {
                $storage->publicPath($path);
                $this->fail('Unsafe storage path was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('图片路径无效。', $exception->getMessage());
            }
        }
        $this->assertSame(storage_path('app/originals/2026/10/'.str_repeat('a', 32).'.png'), $storage->privatePath('originals', '2026/10/'.str_repeat('a', 32).'.png'));
        $this->expectException(InvalidArgumentException::class);
        $storage->privatePath('../public', '2026/10/'.str_repeat('a', 32).'.webp');
    }

    private function image(string $format, int $width, int $height, string $color = '#317c73'): UploadedFile
    {
        $image = new \Imagick;
        $image->newImage($width, $height, new \ImagickPixel($color));
        $image->setImageFormat($format);
        $path = $this->temporaryDirectory.'/'.bin2hex(random_bytes(8)).'.'.$format;
        $image->writeImage($path);
        $image->clear();

        return new UploadedFile($path, '测试图片.'.$format, test: true);
    }
}
