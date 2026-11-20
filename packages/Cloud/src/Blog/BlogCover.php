<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Blog;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;

/** @phpstan-type Manifest array{version: string, dimensions: array{int, int}, widths: list<int>} */
final readonly class BlogCover
{
    public FilesystemAdapter $disk;

    private const string DIRECTORY = 'blog-covers/v1';

    private string $manifestPath;

    private string $cacheKey;

    public function __construct(public string $source)
    {
        $diskName = config('ink.uploads.disk', 'public');
        $this->disk = Storage::disk($diskName);
        $this->manifestPath = self::DIRECTORY.'/sources/'.hash('sha256', $source).'.json';
        $this->cacheKey = "blog-cover:{$diskName}:{$this->manifestPath}";
    }

    public function url(): string
    {
        return url($this->disk->url($this->source));
    }

    /** @return Manifest|array{} */
    public function manifest(): array
    {
        return Cache::memo()->remember($this->cacheKey, now()->addMinutes(5), function (): array {
            try {
                return $this->storedManifest();
            } catch (FilesystemException $exception) {
                report($exception);

                return [];
            }
        });
    }

    public function hasVersion(string $version): bool
    {
        $manifest = $this->storedManifest();

        return ($manifest['version'] ?? null) === $version
            && array_all($manifest['widths'], fn (int $width): bool => $this->disk->fileExists($this->variantPath($version, $width)));
    }

    /** @return Manifest|array{} */
    private function storedManifest(): array
    {
        return $this->disk->fileExists($this->manifestPath) ? ($this->disk->json($this->manifestPath) ?? []) : [];
    }

    public function srcset(): string
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            return '';
        }

        return implode(', ', array_map(function (int $width) use ($manifest): string {
            $url = $this->disk->url($this->variantPath($manifest['version'], $width));

            return "{$url} {$width}w";
        }, $manifest['widths']));
    }

    public function variantPath(string $version, int $width): string
    {
        return self::DIRECTORY."/{$version}/{$width}.webp";
    }

    public function write(string $path, string $contents): void
    {
        if ($this->disk instanceof AwsS3V3Adapter) {
            throw_unless($this->disk->put($path, $contents), RuntimeException::class, "Could not publish {$path}.");

            return;
        }

        $temporary = $path.'.'.Str::uuid().'.tmp';

        try {
            throw_if(! $this->disk->put($temporary, $contents) || ! $this->disk->move($temporary, $path), RuntimeException::class, "Could not publish {$path}.");
        } finally {
            $this->disk->delete($temporary);
        }
    }

    /** @param Manifest $manifest */
    public function publish(array $manifest): void
    {
        $this->write($this->manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        Cache::memo()->forever($this->cacheKey, $manifest);
    }
}
