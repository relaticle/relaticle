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
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Enums\ConnectableAssistant;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
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

                $name = LocalizedUrl::baseRouteNameOf($path);

                if ($name !== null && Str::startsWith($name, ['help.', 'documentation.', 'blog.'])) {
                    return false;
                }

                if (parse_url($url, PHP_URL_QUERY) !== null) {
                    return false;
                }

                return pathinfo($path, PATHINFO_EXTENSION) === '';
            })
            ->getSitemap();
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
        $alternates = array_diff_key(LocalizedUrl::alternatesOf($name, $parameters), ['x-default' => true]);

        $this->addAlternateSet($sitemap, array_map(fn (string $url): array => [$url, null], $alternates));
    }

    private function addDocumentationUrls(Sitemap $sitemap, DocsRepository $docsRepository): void
    {
        $versions = [];

        foreach (config('app.available_locales') as $locale) {
            Locales::within($locale, function () use ($docsRepository, $locale, &$versions): void {
                foreach ($docsRepository->categories($locale) as $category) {
                    if ($category->area === DocUrl::HELP) {
                        $versions[$category->path][$locale] = [DocUrl::category($category), null];
                    }
                }

                foreach ($docsRepository->pages($locale) as $page) {
                    $versions[$page->path][$locale] = [DocUrl::page($page), $page->updated];
                }
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
