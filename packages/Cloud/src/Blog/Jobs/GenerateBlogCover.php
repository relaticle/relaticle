<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog\Jobs;

use App\Support\Media\LocalCopy;
use GdImage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\File;
use Imagick;
use Relaticle\Cloud\Blog\BlogCover;
use Relaticle\Ink\Models\Post;
use RuntimeException;
use Spatie\Image\Image;

#[Queue('imports')]
#[Tries(3)]
#[Backoff([10, 60])]
#[UniqueFor(3600)]
final class GenerateBlogCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const array WIDTHS = [224, 320, 512, 768, 960, 1200, 1600, 2400];

    // The imports worker has 256 MB for the decoder, resize buffers and encoder.
    private const int MAX_SOURCE_PIXELS = 8_000_000;

    private const int MAX_SOURCE_BYTES = 32 * 1024 * 1024;

    private const int MAX_WEBP_HEIGHT = 16383;

    public function __construct(public readonly string $source) {}

    public function uniqueId(): string
    {
        return $this->source;
    }

    public function handle(): void
    {
        if (! Post::query()->where('featured_image', $this->source)->exists()) {
            return;
        }

        $cover = new BlogCover($this->source);
        LocalCopy::of($cover->disk->readStream($this->source), fn (string $path) => $this->generate($cover, $path));
    }

    private function generate(BlogCover $cover, string $path): void
    {
        $version = hash_file('sha256', $path);
        throw_if($version === false, RuntimeException::class, "Could not read blog cover {$this->source}.");
        if ($cover->hasVersion($version)) {
            return;
        }

        $dimensions = @getimagesize($path);
        if (! $dimensions || ! in_array($dimensions[2], [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException("Unsupported blog cover {$this->source}.");
        }

        [$width, $height, $type] = $dimensions;
        $widths = [];
        if ($width * $height > self::MAX_SOURCE_PIXELS || filesize($path) > self::MAX_SOURCE_BYTES || $this->isAnimated($path, $type)) {
            $exif = $type === IMAGETYPE_JPEG ? @exif_read_data($path, 'IFD0') : false;
            if ($exif !== false && in_array($exif['Orientation'] ?? null, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        } else {
            $image = Image::load($path);
            [$width, $height] = [$image->getWidth(), $image->getHeight()];
            throw_if($width < 1 || $height < 1, RuntimeException::class, "Invalid dimensions for {$this->source}.");
            foreach (self::WIDTHS as $target) {
                $candidate = min($target, $width);
                $scaledHeight = max(1, (int) round($candidate * $height / $width));
                if ($scaledHeight > self::MAX_WEBP_HEIGHT) {
                    break;
                }

                $webp = $this->encode($image->image(), $candidate, $scaledHeight);
                $cover->write($cover->variantPath($version, $candidate), $webp);
                $widths[] = $candidate;
                if ($candidate === $width) {
                    break;
                }
            }
        }

        $cover->publish(['version' => $version, 'dimensions' => [$width, $height], 'widths' => $widths]);
    }

    private function isAnimated(string $path, int $type): bool
    {
        if ($type === IMAGETYPE_GIF) {
            if (! class_exists(Imagick::class)) {
                return true;
            }

            $image = new Imagick;
            $image->pingImage($path);

            return $image->getNumberImages() > 1;
        }

        if ($type === IMAGETYPE_WEBP) {
            $header = file_get_contents($path, false, null, 12, 9);

            return $header !== false && strlen($header) === 9 && str_starts_with($header, 'VP8X') && (ord($header[8]) & 0x02) !== 0;
        }

        // Animated PNG requires an eight-byte acTL chunk before the first frame.
        return $type === IMAGETYPE_PNG && str_contains(File::get($path), "\x00\x00\x00\x08acTL");
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function encode(Imagick|GdImage $source, int $width, int $height): string
    {
        if ($source instanceof Imagick) {
            $image = clone $source;
            $image->scaleImage($width, $height);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(86);

            return $image->getImageBlob();
        }

        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        ob_start();
        try {
            imagewebp($image, null, 86);
            $webp = ob_get_contents();
            throw_unless($webp, RuntimeException::class, "Could not encode a {$width}px WebP for {$this->source}.");

            return $webp;
        } finally {
            ob_end_clean();
        }
    }
}
