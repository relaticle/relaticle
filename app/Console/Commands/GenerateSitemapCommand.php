<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Blog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Relaticle\Ink\BlogSitemapGenerator;
use Spatie\Sitemap\SitemapGenerator;

#[Description('Generate the sitemap')]
#[Signature('app:generate-sitemap')]
final class GenerateSitemapCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // /dashboard and /forgot-password are not linked from any crawled page
        // (their only links live behind auth or on token-gated invitation
        // pages) -- they're excluded as a deliberate, untested guard rather
        // than covered behavior.
        $excluded = ['/login', '/register', '/forgot-password', '/dashboard', '/discord'];

        $sitemap = SitemapGenerator::create(config('app.url'))
            ->shouldCrawl(fn (string $url): bool => ! in_array(parse_url($url, PHP_URL_PATH), $excluded, true))
            ->getSitemap();

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }
}
