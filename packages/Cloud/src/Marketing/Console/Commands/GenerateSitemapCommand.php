<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Console\Commands;

use App\Features\Blog;
use App\Features\EmailIntegration;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Enums\ConnectableAssistant;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
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
        $sitemap = $this->crawlMarketingSitemap();

        $this->addMarketingUrls($sitemap);
        $this->addDocumentationUrls($sitemap, $docsRepository);

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToDisk('public', 'sitemap.xml');
    }

    private function crawlMarketingSitemap(): Sitemap
    {
        // /dashboard and /forgot-password are not linked from any crawled page
        // (their only links live behind auth or on token-gated invitation
        // pages) -- they're excluded as a deliberate, untested guard rather
        // than covered behavior.
        $excluded = ['/login', '/register', '/forgot-password', '/dashboard', '/discord'];

        return SitemapGenerator::create(config('app.url'))
            ->shouldCrawl(function (string $url) use ($excluded): bool {
                $path = (string) parse_url($url, PHP_URL_PATH);

                if (in_array($path, $excluded, true)) {
                    return false;
                }

                if (preg_match($this->explicitSectionsPattern(), $path) === 1) {
                    return false;
                }

                if (parse_url($url, PHP_URL_QUERY) !== null) {
                    return false;
                }

                return pathinfo($path, PATHINFO_EXTENSION) === '';
            })
            ->getSitemap();
    }

    private function explicitSectionsPattern(): string
    {
        $locales = implode('|', array_diff(config('app.available_locales'), [LocalizedUrl::DEFAULT_LOCALE]));

        return "#^/(?:(?:{$locales})/)?(?:blog|help|developers(?!/api\\b))(?:/|\$)#";
    }

    private function addMarketingUrls(Sitemap $sitemap): void
    {
        $names = ['home', 'pricing', 'press', 'ai', 'aiNativeCrm', 'selfHosted', 'security', 'contact', 'terms.show', 'policy.show', 'help.index', 'documentation.index'];

        foreach (ConnectableAssistant::cases() as $assistant) {
            $names[] = $assistant->routeName();
        }

        if (Feature::active(EmailIntegration::class)) {
            $names[] = 'emailAndCalendar';
        }

        $pages = array_map(fn (string $name): array => [$name, []], $names);

        foreach (config('comparisons.compare', []) as $competitor) {
            $pages[] = ['compare.show', ['competitor' => $competitor]];
        }

        foreach (config('comparisons.alternatives', []) as $competitor) {
            $pages[] = ['alternatives.show', ['competitor' => $competitor]];
        }

        foreach ($pages as [$name, $parameters]) {
            $this->addLocalizedPage($sitemap, $name, $parameters);
        }
    }

    /** @param  array<string, string>  $parameters */
    private function addLocalizedPage(Sitemap $sitemap, string $name, array $parameters): void
    {
        $alternates = LocalizedUrl::alternatesOf($name, $parameters);

        foreach (array_diff_key($alternates, ['x-default' => true]) as $url) {
            $tag = $this->ensureUrl($sitemap, $url);

            foreach ($alternates as $hreflang => $href) {
                $tag->addAlternate($href, $hreflang);
            }
        }
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
        $versions = [];

        foreach (config('app.available_locales') as $locale) {
            Locales::within($locale, function () use ($docsRepository, $locale, &$versions): void {
                $docsRepository->categories($locale)
                    ->filter(fn (DocCategory $category): bool => $category->area === DocUrl::HELP)
                    ->each(function (DocCategory $category) use ($locale, &$versions): void {
                        $versions[$category->path][$locale] = [DocUrl::category($category), null];
                    });

                $docsRepository->pages($locale)->each(function (DocPage $page) use ($locale, &$versions): void {
                    $versions[$page->path][$locale] = [DocUrl::page($page), $page->updated];
                });
            });
        }

        foreach ($versions as $pageVersions) {
            $this->addAlternateSet($sitemap, $pageVersions);
        }
    }

    /** @param  array<string, array{0: string, 1: ?CarbonImmutable}>  $versions */
    private function addAlternateSet(Sitemap $sitemap, array $versions): void
    {
        $alternates = array_map(fn (array $version): string => $version[0], $versions);

        if (isset($alternates[LocalizedUrl::DEFAULT_LOCALE])) {
            $alternates['x-default'] = $alternates[LocalizedUrl::DEFAULT_LOCALE];
        }

        foreach ($versions as [$location, $lastModified]) {
            $tag = $this->ensureUrl($sitemap, $location, $lastModified);

            foreach ($alternates as $hreflang => $href) {
                $tag->addAlternate($href, $hreflang);
            }
        }
    }

    private function ensureUrl(Sitemap $sitemap, string $location, ?CarbonImmutable $lastModified = null): Url
    {
        $url = $sitemap->getUrl($location);

        if (! $url instanceof Url) {
            $url = Url::create($location);
            $sitemap->add($url);
        }

        if ($lastModified instanceof CarbonImmutable) {
            $url->setLastModificationDate($lastModified);
        }

        return $url;
    }
}
