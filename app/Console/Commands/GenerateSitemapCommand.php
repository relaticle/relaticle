<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Blog;
use App\Features\Marketing;
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
        $sitemap = Feature::active(Marketing::class)
            ? $this->crawlMarketingSitemap()
            : Sitemap::create();

        $this->addDocumentationUrls($sitemap, $docsRepository);

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }

    /**
     * Self-hosted forks with Marketing disabled have no public marketing surface
     * to crawl -- `/` just redirects to the app login. Crawling it anyway is
     * actively harmful: spatie/crawler follows redirects and resolves links found
     * on the redirected-to page against its effective URL, so a link on the login
     * screen would get enqueued and leak an app-panel-adjacent URL into the
     * public sitemap. Documentation and blog URLs are added through their own
     * non-crawl code paths below, so skipping the crawl entirely is safe.
     */
    private function crawlMarketingSitemap(): Sitemap
    {
        // /dashboard and /forgot-password are not linked from any crawled page
        // (their only links live behind auth or on token-gated invitation
        // pages) -- they're excluded as a deliberate, untested guard rather
        // than covered behavior.
        $excluded = ['/login', '/register', '/forgot-password', '/dashboard', '/discord'];

        return SitemapGenerator::create(config('app.url'))
            ->shouldCrawl(fn (string $url): bool => ! in_array(parse_url($url, PHP_URL_PATH), $excluded, true))
            ->getSitemap();
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
