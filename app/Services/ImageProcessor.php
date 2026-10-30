<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImageProcessor
{
    /** @return array<string, mixed> */
    public function process(UploadedFile $file, array $options): array
    {
        if (! extension_loaded('imagick') || ! in_array('WEBP', \Imagick::queryFormats('WEBP'), true)) {
            throw new InvalidArgumentException('服务器未启用 Imagick WebP 支持，请在运行状态中检查。');
        }
        $options = $this->normalizeOptions($options);
        if (! $file->isValid() || ! is_file($file->getPathname())) {
            throw new InvalidArgumentException('上传未完成或临时文件不存在，请重新选择图片。');
        }
        $size = filesize($file->getPathname());
        if ($size === false || $size === 0 || $size > $options['max_file_mb'] * 1024 * 1024) {
            throw new InvalidArgumentException('单张图片不得超过 '.$options['max_file_mb'].' MB，且不能为空。');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime])) {
            throw new InvalidArgumentException('仅支持 JPEG、PNG 和静态 WebP；不支持 GIF、SVG 或其他格式。');
        }
        $dimensions = @getimagesize($file->getPathname());
        if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw new InvalidArgumentException('无法读取图片尺寸，文件可能已损坏。');
        }
        if ($dimensions[0] > intdiv($options['max_pixels'], $dimensions[1])) {
            throw new InvalidArgumentException('图片像素总数超过限制（'.$options['max_pixels'].' 像素）。');
        }
        $webp = null;
        if ($mime === 'image/webp') {
            $webp = $this->stripWebpMetadata(file_get_contents($file->getPathname()));
        } elseif ($mime === 'image/png') {
            $this->validateStaticPng(file_get_contents($file->getPathname()));
        }
        $tempPaths = [];
        $image = null;
        $preview = null;
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 1024 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
            $image = new \Imagick;
            $image->readImage($file->getPathname());
            if ($image->getNumberImages() !== 1) {
                throw new InvalidArgumentException('不支持动态图片，请上传静态 JPEG、PNG 或 WebP。');
            }
            $orientation = $image->getImageOrientation();
            $needsOrientation = ! in_array($orientation, [\Imagick::ORIENTATION_UNDEFINED, \Imagick::ORIENTATION_TOPLEFT], true);
            $image->autoOrient();
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            if ($image->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $resize = $options['max_edge'] > 0 && max($width, $height) > $options['max_edge'];
            if ($resize) {
                $scale = $options['max_edge'] / max($width, $height);
                $image->resizeImage(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), \Imagick::FILTER_LANCZOS, 1);
            }
            $image->stripImage();
            $image->setImagePage(0, 0, 0, 0);
            $temp = $this->temporaryPath();
            $tempPaths[] = $temp;
            $reuse = $webp !== null && ! $resize && ! $needsOrientation && ! $options['lossless'];
            if ($reuse) {
                if (file_put_contents($temp, $webp) !== strlen($webp)) {
                    throw new RuntimeException('写入暂存图片失败。');
                }
            } else {
                $image->setImageFormat('WEBP');
                $image->setImageCompressionQuality($options['quality']);
                $image->setOption('webp:lossless', $options['lossless'] ? 'true' : 'false');
                $image->setOption('webp:method', '5');
                if (! $image->writeImage($temp)) {
                    throw new RuntimeException('WebP 转换失败。');
                }
            }
            $outputSize = @getimagesize($temp);
            if ($outputSize === false || ($outputSize['mime'] ?? '') !== 'image/webp' || filesize($temp) === 0) {
                throw new RuntimeException('转换结果验证失败，未发布图片。');
            }
            $preview = clone $image;
            if (max($preview->getImageWidth(), $preview->getImageHeight()) > 480) {
                $preview->thumbnailImage(480, 480, true);
            }
            $preview->stripImage();
            $preview->setImageFormat('WEBP');
            $preview->setImageCompressionQuality(76);
            $preview->setOption('webp:lossless', 'false');
            $previewPath = $this->temporaryPath();
            $tempPaths[] = $previewPath;
            if (! $preview->writeImage($previewPath) || (@getimagesize($previewPath)['mime'] ?? '') !== 'image/webp') {
                throw new RuntimeException('WebP 预览生成失败。');
            }
            $hash = hash_file('sha256', $file->getPathname());
            $processing = array_intersect_key($options, array_flip(['quality', 'lossless', 'max_edge', 'keep_original']));
            $fingerprint = hash('sha256', $hash.'|'.json_encode($processing, JSON_THROW_ON_ERROR));

            return [
                'temp_path' => $temp,
                'preview_temp_path' => $previewPath,
                'source_extension' => $extensions[$mime],
                'source_mime' => $mime,
                'source_hash' => $hash,
                'fingerprint' => $fingerprint,
                'source_width' => $dimensions[0],
                'source_height' => $dimensions[1],
                'width' => $outputSize[0],
                'height' => $outputSize[1],
                'source_bytes' => $size,
                'bytes' => filesize($temp),
                'processing' => [...$processing, 'reused_webp' => $reuse],
            ];
        } catch (Throwable $exception) {
            foreach ($tempPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            report($exception);
            throw new InvalidArgumentException('图片处理失败，文件可能已损坏或超过服务器资源限制；未发布任何原格式文件。', previous: $exception);
        } finally {
            $image?->clear();
            $preview?->clear();
        }
    }

    public function cleanup(array $result): void
    {
        $directory = realpath(storage_path('app/tmp'));
        foreach (['temp_path', 'preview_temp_path'] as $key) {
            $path = $result[$key] ?? null;
            if ($directory && is_string($path) && str_starts_with($path, $directory.DIRECTORY_SEPARATOR) && is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @return array{quality:int,lossless:bool,max_edge:int,keep_original:bool,max_pixels:int,max_file_mb:int} */
    public function normalizeOptions(array $options): array
    {
        return [
            'quality' => max(1, min(100, (int) ($options['quality'] ?? 80))),
            'lossless' => filter_var($options['lossless'] ?? false, FILTER_VALIDATE_BOOL),
            'max_edge' => max(0, min(32768, (int) ($options['max_edge'] ?? 0))),
            'keep_original' => filter_var($options['keep_original'] ?? false, FILTER_VALIDATE_BOOL),
            'max_pixels' => max(1, min(100000000, (int) ($options['max_pixels'] ?? 40000000))),
            'max_file_mb' => max(1, min(100, (int) ($options['max_file_mb'] ?? 20))),
        ];
    }

    private function temporaryPath(): string
    {
        $directory = storage_path('app/tmp');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('无法创建图片处理临时目录。');
        }
        $path = tempnam($directory, 'image-');
        if ($path === false) {
            throw new RuntimeException('无法创建图片处理临时文件。');
        }
        chmod($path, 0600);

        return $path;
    }

    private function stripWebpMetadata(string $data): string
    {
        if (strlen($data) < 20 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP' || unpack('V', substr($data, 4, 4))[1] + 8 !== strlen($data)) {
            throw new InvalidArgumentException('WebP 文件结构无效或不完整。');
        }
        $offset = 12;
        $chunks = '';
        $hasImage = false;
        while ($offset < strlen($data)) {
            if ($offset + 8 > strlen($data)) {
                throw new InvalidArgumentException('WebP 文件结构损坏。');
            }
            $type = substr($data, $offset, 4);
            $length = unpack('V', substr($data, $offset + 4, 4))[1];
            $padded = $length + ($length % 2);
            if ($offset + 8 + $padded > strlen($data)) {
                throw new InvalidArgumentException('WebP 文件数据不完整。');
            }
            $payload = substr($data, $offset + 8, $length);
            if (in_array($type, ['ANIM', 'ANMF'], true) || ($type === 'VP8X' && $length >= 1 && (ord($payload[0]) & 0x02) !== 0)) {
                throw new InvalidArgumentException('不支持动态 WebP，请先导出为静态图片。');
            }
            if ($type === 'VP8X') {
                if ($length !== 10) {
                    throw new InvalidArgumentException('WebP 扩展头无效。');
                }
                $payload[0] = chr(ord($payload[0]) & ~0x2C);
            }
            if (in_array($type, ['VP8 ', 'VP8L'], true)) {
                $hasImage = true;
            }
            if (! in_array($type, ['EXIF', 'XMP ', 'ICCP'], true)) {
                $chunks .= $type.pack('V', $length).$payload.($length % 2 ? "\0" : '');
            }
            $offset += 8 + $padded;
        }
        if (! $hasImage) {
            throw new InvalidArgumentException('WebP 文件中没有静态图像。');
        }

        return 'RIFF'.pack('V', strlen($chunks) + 4).'WEBP'.$chunks;
    }

    private function validateStaticPng(string $data): void
    {
        if (! str_starts_with($data, "\x89PNG\r\n\x1a\n")) {
            throw new InvalidArgumentException('PNG 文件结构无效。');
        }
        $offset = 8;
        while ($offset < strlen($data)) {
            if ($offset + 12 > strlen($data)) {
                throw new InvalidArgumentException('PNG 文件数据不完整。');
            }
            $length = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($offset + 12 + $length > strlen($data)) {
                throw new InvalidArgumentException('PNG 文件分块损坏。');
            }
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                throw new InvalidArgumentException('不支持动态 PNG（APNG），请先导出为静态图片。');
            }
            $offset += 12 + $length;
            if ($type === 'IEND') {
                if ($length !== 0 || $offset !== strlen($data)) {
                    throw new InvalidArgumentException('PNG 文件结束标记无效。');
                }

                return;
            }
        }
        throw new InvalidArgumentException('PNG 文件缺少结束标记。');
    }
}
