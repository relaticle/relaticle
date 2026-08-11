<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Blog;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Relaticle\Ink\BlogSitemapGenerator;
use Spatie\Sitemap\SitemapGenerator;
use Spatie\Sitemap\Tags\Url;

#[Description('Generate the sitemap')]
#[Signature('app:generate-sitemap')]
final class GenerateSitemapCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $excluded = ['/login', '/register', '/forgot-password', '/dashboard', '/discord'];

        $sitemap = SitemapGenerator::create(config('app.url'))
            ->shouldCrawl(fn (string $url): bool => ! in_array(parse_url($url, PHP_URL_PATH), $excluded, true))
            ->hasCrawled(function (Url $url) use ($excluded): ?Url {
                if (in_array($url->path(), $excluded, true)) {
                    return null;
                }

                if ($lastModified = $this->documentationLastModified($url)) {
                    $url->setLastModificationDate($lastModified);
                }

                return $url;
            })
            ->getSitemap();

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }

    private function documentationLastModified(Url $url): ?DateTimeInterface
    {
        $path = $url->path();

        if (! str_starts_with($path, '/docs/')) {
            return null;
        }

        $type = substr($path, strlen('/docs/'));
        $file = config("documentation.documents.{$type}.file");

        if (! is_string($file)) {
            return null;
        }

        $basePath = config('documentation.markdown.base_path');

        if (! is_string($basePath)) {
            return null;
        }

        $fullPath = "{$basePath}/{$file}";

        return file_exists($fullPath)
            ? (new DateTimeImmutable)->setTimestamp((int) filemtime($fullPath))
            : null;
    }
}
