<?php

namespace App\Services\Images;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageConversionService
{
    /**
     * @return array{gd_loaded: bool, imagecreatefromstring: bool, imagewebp: bool, imagetypes_webp: bool, webp_supported: bool}
     */
    public function capabilities(): array
    {
        $imageTypesWebp = defined('IMG_WEBP')
            && function_exists('imagetypes')
            && ((imagetypes() & IMG_WEBP) === IMG_WEBP);

        return [
            'gd_loaded' => extension_loaded('gd'),
            'imagecreatefromstring' => function_exists('imagecreatefromstring'),
            'imagewebp' => function_exists('imagewebp'),
            'imagetypes_webp' => $imageTypesWebp,
            'webp_supported' => extension_loaded('gd')
                && function_exists('imagecreatefromstring')
                && function_exists('imagewebp')
                && $imageTypesWebp,
        ];
    }

    public function canConvertToWebp(): bool
    {
        return $this->capabilities()['webp_supported'];
    }

    public function assertWebpAvailable(): void
    {
        if (! $this->canConvertToWebp()) {
            throw new RuntimeException('Імпорт неможливий: PHP GD/WebP не увімкнено. Увімкніть ext-gd з WebP або встановіть Imagick.');
        }
    }

    /**
     * @return array{width: int, height: int, mime_type: string, size: int}
     */
    public function probeWebpConversion(string $bytes): array
    {
        $this->assertWebpAvailable();

        $source = @imagecreatefromstring($bytes);

        if (! $source) {
            throw new RuntimeException('Не вдалося прочитати завантажене зображення.');
        }

        ob_start();
        $converted = @imagewebp($source, null, 82);
        $webp = ob_get_clean();
        $width = imagesx($source);
        $height = imagesy($source);
        imagedestroy($source);

        if (! $converted || ! is_string($webp) || $webp === '') {
            throw new RuntimeException('Не вдалося виконати тестову WebP конвертацію.');
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime_type' => 'image/webp',
            'size' => strlen($webp),
        ];
    }

    /**
     * @return array{path: string, width: int, height: int, mime_type: string, size: int}
     */
    public function convertToWebp(Product $product, string $bytes, string $directory = 'product-gallery'): array
    {
        $this->assertWebpAvailable();

        $source = @imagecreatefromstring($bytes);

        if (! $source) {
            throw new RuntimeException('Не вдалося прочитати завантажене зображення.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $maxWidth = 1200;
        $maxHeight = 1200;
        $ratio = min(1, $maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
        $targetWidth = max(1, (int) floor($sourceWidth * $ratio));
        $targetHeight = max(1, (int) floor($sourceHeight * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        $directory = trim($directory, '/').'/'.$product->id;
        $filename = Str::slug($product->slug ?: $product->name).'-'.Str::lower(Str::random(10)).'.webp';
        $path = $directory.'/'.$filename;
        $disk = Storage::disk('public');
        $disk->makeDirectory($directory);
        $absolutePath = $disk->path($path);

        if (! @imagewebp($target, $absolutePath, 82)) {
            imagedestroy($source);
            imagedestroy($target);

            throw new RuntimeException('Не вдалося зберегти WebP файл.');
        }

        imagedestroy($source);
        imagedestroy($target);

        return [
            'path' => $path,
            'width' => $targetWidth,
            'height' => $targetHeight,
            'mime_type' => 'image/webp',
            'size' => (int) filesize($absolutePath),
        ];
    }
}
