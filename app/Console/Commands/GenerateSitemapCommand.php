<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Features\Blog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;
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

        $this->addHelpUrls($sitemap, $docsRepository);

        if (Feature::active(Blog::class)) {
            BlogSitemapGenerator::addToSitemap($sitemap);
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));
    }

    /**
     * The crawler only follows links reachable from the homepage, and /help
     * isn't linked from anywhere yet, so it needs adding explicitly -- the
     * same way BlogSitemapGenerator does. Only a page's own front-matter
     * `updated:` date produces a <lastmod>; a missing one is left out
     * entirely rather than falling back to a file timestamp.
     */
    private function addHelpUrls(Sitemap $sitemap, DocsRepository $docsRepository): void
    {
        $sitemap->add(Url::create(route('help.index')));

        $docsRepository->categories()
            ->filter(fn (DocCategory $category): bool => $category->area === 'help')
            ->each(function (DocCategory $category) use ($sitemap, $docsRepository): void {
                $sitemap->add(Url::create(route('help.category', [
                    'category' => Str::after($category->path, '/'),
                ])));

                $docsRepository->pagesIn($category->path)->each(function (DocPage $page) use ($sitemap): void {
                    $url = Url::create(route('help.show', [
                        'category' => $page->category,
                        'slug' => $page->slug,
                    ]));

                    if ($page->updated) {
                        $url->setLastModificationDate($page->updated);
                    }

                    $sitemap->add($url);
                });
            });
    }
}
