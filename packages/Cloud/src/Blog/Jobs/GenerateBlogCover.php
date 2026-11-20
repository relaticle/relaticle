<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog\Jobs;

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
use Spatie\TemporaryDirectory\TemporaryDirectory;

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
        $contents = $cover->disk->get($this->source) ?? throw new RuntimeException("Missing blog cover {$this->source}.");
        $version = hash('sha256', $contents);

        if ($cover->hasVersion($version)) {
            return;
        }

        $dimensions = @getimagesizefromstring($contents);
        if (! $dimensions || ! in_array($dimensions[2], [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new RuntimeException("Unsupported blog cover {$this->source}.");
        }

        $temporary = TemporaryDirectory::make();
        try {
            $original = $temporary->path('original.image');
            File::put($original, $contents);
            [$width, $height, $type] = $dimensions;
            $widths = [];
            if ($width * $height > self::MAX_SOURCE_PIXELS || $this->isAnimated($contents, $type, $original)) {
                $exif = $type === IMAGETYPE_JPEG && function_exists('exif_read_data') ? @exif_read_data($original, 'IFD0') : false;
                if ($exif !== false && in_array($exif['Orientation'] ?? null, [5, 6, 7, 8], true)) {
                    [$width, $height] = [$height, $width];
                }
            } else {
                $image = Image::load($original);
                [$width, $height] = [$image->getWidth(), $image->getHeight()];
                unset($image);
                $widths = $this->writeVariants($cover, $version, $width, $original, $temporary->path('cover.webp'));
            }

            $cover->publish(['version' => $version, 'dimensions' => [$width, $height], 'widths' => $widths]);
        } finally {
            $temporary->delete();
        }
    }

    private function isAnimated(string $contents, int $type, string $original): bool
    {
        if ($type === IMAGETYPE_GIF) {
            if (! class_exists(Imagick::class)) {
                return true;
            }

            $image = new Imagick;
            $image->pingImage($original);

            return $image->getNumberImages() > 1;
        }

        // PNG fixes the acTL payload at eight bytes. Bit 1 of the WebP VP8X flags signals animation.
        return match ($type) {
            IMAGETYPE_PNG => str_contains($contents, "\x00\x00\x00\x08acTL"),
            IMAGETYPE_WEBP => substr($contents, 12, 4) === 'VP8X' && (ord($contents[20]) & 0x02) !== 0,
            default => false,
        };
    }

    /** @return list<int> */
    private function writeVariants(BlogCover $cover, string $version, int $width, string $original, string $output): array
    {
        $widths = array_values(array_unique(array_map(fn (int $candidate): int => min($candidate, $width), self::WIDTHS)));

        foreach ($widths as $candidate) {
            Image::load($original)->width($candidate)->quality(86)->save($output);
            $webp = File::get($output);
            $dimensions = getimagesizefromstring($webp);
            if (! $dimensions || $dimensions[0] !== $candidate || $dimensions[2] !== IMAGETYPE_WEBP) {
                throw new RuntimeException("Could not encode a {$candidate}px WebP for {$this->source}.");
            }
            $cover->write($cover->variantPath($version, $candidate), $webp);
        }

        return $widths;
    }
}
