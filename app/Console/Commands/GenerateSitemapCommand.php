<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Blog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\DocUrl;
use Relaticle\Ink\BlogSitemapGenerator;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\SitemapGenerator;
use Spatie\Sitemap\Tags\Url;

#[Description('Generate the sitemap')]
#[Signature('app:generate-sitemap')]
final class GenerateSitemapCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocsRepository $docsRepository): void
    {
        // /dashboard and /forgot-password are not linked from any crawled page
        // (their only links live behind auth or on token-gated invitation
        // pages) -- they're excluded as a deliberate, untested guard rather
        // than covered behavior.
        $excluded = ['/login', '/register', '/forgot-password', '/dashboard', '/discord'];

        $sitemap = SitemapGenerator::create(config('app.url'))
            ->shouldCrawl(fn (string $url): bool => ! in_array(parse_url($url, PHP_URL_PATH), $excluded, true))
            ->getSitemap();

        $this->addDocumentationUrls($sitemap, $docsRepository);

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }

    /**
     * The crawler usually discovers the documentation pages itself (they're
     * linked from the marketing nav), but a crawl-found entry carries no
     * <lastmod> -- and Sitemap::add() keeps the first tag per URL, so adding
     * again would be silently ignored. Merge instead: stamp lastmod onto the
     * existing tag, or add the URL when the crawl missed it. Only a page's
     * own front-matter `updated:` produces a <lastmod>; a missing one is
     * left out entirely rather than falling back to a file timestamp.
     */
    private function addDocumentationUrls(Sitemap $sitemap, DocsRepository $docsRepository): void
    {
        $this->ensureUrl($sitemap, route('help.index'));
        $this->ensureUrl($sitemap, route('documentation.index'));

        $docsRepository->categories()
            ->filter(fn (DocCategory $category): bool => $category->area === DocUrl::HELP)
            ->each(fn (DocCategory $category) => $this->ensureUrl($sitemap, DocUrl::category($category)));

        $docsRepository->pages()->each(function (DocPage $page) use ($sitemap): void {
            $this->ensureUrl($sitemap, DocUrl::page($page), $page->updated);
        });
    }

    private function ensureUrl(Sitemap $sitemap, string $location, ?CarbonImmutable $lastModified = null): void
    {
        $url = $sitemap->getUrl($location);

        if (! $url instanceof Url) {
            $url = Url::create($location);
            $sitemap->add($url);
        }

        if ($lastModified instanceof CarbonImmutable) {
            $url->setLastModificationDate($lastModified);
        }
    }
}
