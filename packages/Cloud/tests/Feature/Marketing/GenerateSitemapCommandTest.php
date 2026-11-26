<?php

declare(strict_types=1);

use App\Features\Blog;
use App\Features\EmailIntegration;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Console\Commands\GenerateSitemapCommand;
use Relaticle\Cloud\Marketing\Support\IndexNow;
use Relaticle\Ink\Models\Post;
use Spatie\Crawler\Faking\FakeHandler;

mutates(GenerateSitemapCommand::class, IndexNow::class);

/**
 * spatie/crawler drives its own Guzzle client, so Illuminate's Http::fake()
 * never reaches it. Route the crawl through the crawler's own fake handler
 * via the guzzle_options config the command already reads from.
 *
 * @param  array<string, string>  $responses
 */
function fakeSitemapCrawl(array $responses): void
{
    config(['sitemap.guzzle_options.handler' => HandlerStack::create(new FakeHandler($responses))]);
}

beforeEach(function (): void {
    // The generator crawls app.url before adding the blog URLs; keep it off the network.
    fakeSitemapCrawl([config('app.url') => '<html><body></body></html>']);
    config()->set('documentation.public_url', url('/'));

    Storage::fake('public');
});

it('writes published posts into the sitemap when the blog is live', function (): void {
    $post = Post::factory()->published()->create();
    $draft = Post::factory()->draft()->create();

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain(route('blog.show', $post->slug))
        ->and($xml)->toContain(route('blog.index'))
        ->and($xml)->not->toContain(route('blog.show', $draft->slug));
});

it('leaves the blog out of the sitemap when the feature is off', function (): void {
    Feature::for(null)->deactivate(Blog::class);

    $post = Post::factory()->published()->create();

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(Storage::disk('public')->get('sitemap.xml'))->not->toContain(route('blog.show', $post->slug));
});

it('lists the email and calendar page only while email integration is on', function (bool $active): void {
    Feature::for(null)->{$active ? 'activate' : 'deactivate'}(EmailIntegration::class);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(str_contains((string) Storage::disk('public')->get('sitemap.xml'), '<loc>'.route('emailAndCalendar').'</loc>'))->toBe($active);
})->with([true, false]);

it('adds help urls to the sitemap with lastmod from front matter', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('help.index').'</loc>')
        ->and($xml)->toContain('<loc>'.route('help.category', ['category' => 'getting-started']).'</loc>')
        ->and($xml)->toContain('<loc>'.route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']).'</loc>')
        ->and($xml)->toMatch('#getting-started/create-your-first-company</loc>(?:(?!</url>).)*?<lastmod>2026-08-12#s');
});

it('stamps lastmod onto urls the crawler already found', function (): void {
    // The marketing nav links /help, so in production the crawler discovers
    // help URLs first -- and Sitemap::add() keeps the first tag per URL. A
    // naive re-add of the lastmod-carrying version is silently dropped,
    // which shipped a sitemap with zero <lastmod> entries. Reproduce the
    // crawl-found-first ordering and require the merge.
    $article = route('help.show', ['category' => 'getting-started', 'slug' => 'create-your-first-company']);

    fakeSitemapCrawl([
        config('app.url') => '<html><body><a href="'.$article.'">Help</a></body></html>',
        $article => '<html><body>article</body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(Storage::disk('public')->get('sitemap.xml'))
        ->toMatch('#getting-started/create-your-first-company</loc>(?:(?!</url>).)*?<lastmod>2026-08-12#s');
});

it('adds developer guide urls with lastmod from front matter', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('documentation.index').'</loc>')
        ->and($xml)->toContain('<loc>'.route('aiNativeCrm').'</loc>')
        ->and($xml)->toContain('<loc>'.route('crmForCleaningBusiness').'</loc>')
        ->and($xml)->toContain('<loc>'.route('assistants.claude').'</loc>')
        ->and($xml)->toContain('<loc>'.route('assistants.chatgpt').'</loc>')
        ->and($xml)->toContain('<loc>'.route('security').'</loc>')
        ->and($xml)->toMatch('#developers/self-hosting</loc>(?:(?!</url>).)*?<lastmod>2026-10-31#s')
        ->and($xml)->toMatch('#developers/mcp</loc>(?:(?!</url>).)*?<lastmod>2026-11-09#s');
});

it('omits lastmod for a help page with no updated front matter', function (): void {
    $fixturePath = storage_path('framework/testing/sitemap-help-'.Str::random(8));

    File::ensureDirectoryExists("{$fixturePath}/help/no-date");
    File::put(
        "{$fixturePath}/help/no-date/_index.md",
        "---\ntitle: No date\ndescription: A category with an undated page.\norder: 1\n---\n\nBody.\n",
    );
    File::put(
        "{$fixturePath}/help/no-date/undated-page.md",
        "---\ntitle: Undated page\ndescription: A page without an updated date.\norder: 1\n---\n\nBody.\n",
    );

    Config::set('documentation.content_path', $fixturePath);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    File::deleteDirectory($fixturePath);

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('help.show', ['category' => 'no-date', 'slug' => 'undated-page']).'</loc>')
        ->and($xml)->not->toMatch('#no-date/undated-page</loc>(?:(?!</url>).)*?<lastmod>#s');
});

it('lists the help pages of each language under its own prefix', function (): void {
    $fixturePath = storage_path('framework/testing/sitemap-help-'.Str::random(8));

    File::ensureDirectoryExists("{$fixturePath}/de/help/guides");
    File::put("{$fixturePath}/de/help/guides/_index.md", "---\ntitle: Anleitungen\ndescription: Deutsche Anleitungen.\norder: 1\n---\n\nInhalt.\n");
    File::put("{$fixturePath}/de/help/guides/widgets.md", "---\ntitle: Widgets\ndescription: Deutsche Widgets.\norder: 1\nupdated: 2026-11-01\n---\n\nInhalt.\n");

    Config::set('documentation.content_path', $fixturePath);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    File::deleteDirectory($fixturePath);

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('de.help.index').'</loc>')
        ->and($xml)->toContain('<loc>'.route('de.help.category', ['category' => 'guides']).'</loc>')
        ->and($xml)->toMatch('#/de/help/guides/widgets</loc>(?:(?!</url>).)*?<lastmod>2026-11-01#s')
        ->and($xml)->not->toContain('/es/help/');
});

it('excludes query string variants of a page already in the sitemap', function (): void {
    fakeSitemapCrawl([
        config('app.url') => '<html><body><a href="'.url('/contact').'">Contact</a>'
            .'<a href="'.url('/contact?plan=enterprise').'">Enterprise</a></body></html>',
        url('/contact') => '<html><body>contact</body></html>',
        url('/contact?plan=enterprise') => '<html><body>contact</body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.url('/contact').'</loc>')
        ->and($xml)->not->toContain('plan=enterprise');
});

it('excludes non-html assets from the sitemap', function (): void {
    fakeSitemapCrawl([
        config('app.url') => '<html><body><a href="'.url('/llms.txt').'">llms.txt</a></body></html>',
        url('/llms.txt') => 'plain text',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(Storage::disk('public')->get('sitemap.xml'))->not->toContain('llms.txt');
});

it('excludes auth and utility redirect urls from the sitemap', function (): void {
    $realHomepageWithLinksToLoginRegisterAndDiscord = (string) $this->get('/')->getContent();

    fakeSitemapCrawl([
        config('app.url') => $realHomepageWithLinksToLoginRegisterAndDiscord,
        url('/login') => '<html><body>login</body></html>',
        url('/register') => '<html><body>register</body></html>',
        url('/discord') => '<html><body>discord</body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('home').'</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/login').'</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/register').'</loc>')
        ->and($xml)->not->toContain('<loc>'.url('/discord').'</loc>');
});

it('serves the generated sitemap from the public disk', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('help.index'), false);
});

it('returns not found before the sitemap is generated', function (): void {
    $this->get('/sitemap.xml')->assertNotFound();
});

it('lists every public page in every language with xhtml:link alternates', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    foreach (['/pricing', '/crm-for-claude', '/compare/relaticle-vs-twenty', '/alternatives/attio'] as $path) {
        foreach (['', '/de', '/es', '/fr'] as $prefix) {
            expect($xml)->toContain('<loc>'.url($prefix.$path).'</loc>');
        }
    }

    preg_match('#<url>\s*<loc>'.preg_quote(url('/de/pricing'), '#').'</loc>.*?</url>#s', $xml, $entry);

    foreach (['en' => '/pricing', 'de' => '/de/pricing', 'es' => '/es/pricing', 'fr' => '/fr/pricing', 'x-default' => '/pricing'] as $hreflang => $path) {
        expect($entry[0])->toContain('hreflang="'.$hreflang.'" href="'.url($path).'"');
    }
});

it('gives every help page and category its language alternates and x-default', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');
    $page = 'help/getting-started/create-your-first-company';

    preg_match('#<url>\s*<loc>'.preg_quote(url('/de/'.$page), '#').'</loc>.*?</url>#s', $xml, $entry);

    foreach (['en' => '', 'de' => '/de', 'es' => '/es', 'fr' => '/fr', 'x-default' => ''] as $hreflang => $prefix) {
        expect($entry[0])->toContain('hreflang="'.$hreflang.'" href="'.url($prefix.'/'.$page).'"');
    }

    preg_match('#<url>\s*<loc>'.preg_quote(url('/fr/help/getting-started'), '#').'</loc>.*?</url>#s', $xml, $category);

    expect($category[0])->toContain('hreflang="de" href="'.url('/de/help/getting-started').'"')
        ->and($category[0])->toContain('hreflang="x-default" href="'.url('/help/getting-started').'"');
});

it('keeps the blog entry of a post the crawl also found', function (): void {
    $post = Post::factory()->published()->create();
    $url = route('blog.show', $post->slug);

    fakeSitemapCrawl([
        config('app.url') => '<html><body><a href="'.route('blog.index').'">Blog</a></body></html>',
        route('blog.index') => '<html><body><a href="'.$url.'">Post</a></body></html>',
        $url => '<html><body></body></html>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect(substr_count($xml, '<loc>'.$url.'</loc>'))->toBe(1)
        ->and($xml)->toMatch('#<loc>'.preg_quote($url, '#').'</loc>(?:(?!</url>).)*?<priority>0.7#s');
});

it('leaves the client-rendered api reference out even when a crawled page links to it', function (): void {
    $reference = route('scribe');
    fakeSitemapCrawl([
        config('app.url') => '<a href="'.$reference.'">API</a>',
        $reference => '<p>API</p>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    expect(Storage::disk('public')->get('sitemap.xml'))->not->toContain('<loc>'.$reference.'</loc>');
});

it('discovers links from the homepage and publishes one root with language alternates', function (): void {
    $root = rtrim(route('home'), '/');
    $child = url('/only-linked-from-home');

    fakeSitemapCrawl([
        config('app.url') => '<a href="'.$child.'">Child</a>',
        $child => '<p>Child</p>',
    ]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = simplexml_load_string(Storage::disk('public')->get('sitemap.xml'));
    $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $homes = $xml->xpath('//s:url[s:loc="'.$root.'" or s:loc="'.$root.'/"]');
    expect($homes)->toHaveCount(1)
        ->and($xml->xpath('//s:url[s:loc="'.$child.'"]'))->toHaveCount(1);

    $alternates = [];
    foreach ($homes[0]->children('http://www.w3.org/1999/xhtml')->link as $alternate) {
        $alternates[(string) $alternate->attributes()['hreflang']] = (string) $alternate->attributes()['href'];
    }
    foreach (config('app.available_locales') as $locale) {
        expect($alternates[$locale])->toBe(route($locale === 'en' ? 'home' : $locale.'.home'));
    }
    expect($alternates['x-default'])->toBe(route('home'));
});

it('lists a translated post with its alternates and leaves out a language with no post', function (): void {
    $group = (string) Str::uuid();
    $english = Post::factory()->published()->create(['locale' => 'en', 'translation_group' => $group]);
    $german = Post::factory()->published()->create(['locale' => 'de', 'translation_group' => $group]);

    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap.xml');

    expect($xml)->toContain('<loc>'.route('blog.de.show', $german->slug).'</loc>')
        ->and($xml)->toContain('hreflang="en" href="'.route('blog.show', $english->slug).'"')
        ->and($xml)->toContain('<loc>'.route('blog.de.index').'</loc>')
        ->and($xml)->not->toContain('/es/blog');
});

it('submits nothing to IndexNow from a host other than relaticle.com', function (): void {
    Http::fake();

    $this->artisan('app:generate-sitemap')->assertSuccessful();
    Post::factory()->published()->create();
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    Http::assertNothingSent();
});

it('serves the configured IndexNow key as plain text at the site root', function (): void {
    config(['services.indexnow.key' => 'a1b2c3d4e5f6a7b8']);

    $this->get('/a1b2c3d4e5f6a7b8.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('a1b2c3d4e5f6a7b8');
});

it('answers not found for any other key, and for every key when none is configured', function (?string $configured): void {
    config(['services.indexnow.key' => $configured]);

    $this->get('/0f0f0f0f0f0f0f0f.txt')->assertNotFound();
})->with(['another key' => 'a1b2c3d4e5f6a7b8', 'no key' => null]);

describe('IndexNow on relaticle.com', function (): void {
    beforeEach(function (): void {
        config([
            'app.url' => 'https://relaticle.com',
            'documentation.public_url' => 'https://relaticle.com',
            'services.indexnow.key' => 'a1b2c3d4e5f6a7b8',
        ]);
        URL::forceRootUrl('https://relaticle.com');
        fakeSitemapCrawl(['https://relaticle.com' => '<html><body></body></html>']);
    });

    it('stays quiet on the first build, with no earlier sitemap to compare', function (): void {
        Http::fake();

        $this->artisan('app:generate-sitemap')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('submits the urls added and removed since the last build', function (): void {
        Http::fake(['api.indexnow.org/*' => Http::response(status: 200)]);
        $removed = Post::factory()->published()->create();

        $this->artisan('app:generate-sitemap')->assertSuccessful();

        $removed->delete();
        $added = Post::factory()->published()->create();

        $this->artisan('app:generate-sitemap')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['host'] === IndexNow::HOST
            && $request['key'] === 'a1b2c3d4e5f6a7b8'
            && $request['keyLocation'] === 'https://relaticle.com/a1b2c3d4e5f6a7b8.txt'
            && in_array(route('blog.show', $added->slug), $request['urlList'], true)
            && in_array(route('blog.show', $removed->slug), $request['urlList'], true)
            && ! in_array(route('pricing'), $request['urlList'], true));
    });

    it('submits nothing while no key is configured', function (): void {
        config(['services.indexnow.key' => null]);
        Http::fake();

        $this->artisan('app:generate-sitemap')->assertSuccessful();
        Post::factory()->published()->create();
        $this->artisan('app:generate-sitemap')->assertSuccessful();

        Http::assertNothingSent();
    });

    it('keeps the build green and reports it when IndexNow refuses the request', function (): void {
        Exceptions::fake();
        Http::fake(['api.indexnow.org/*' => Http::response(status: 429)]);

        $this->artisan('app:generate-sitemap')->assertSuccessful();
        Post::factory()->published()->create();
        $this->artisan('app:generate-sitemap')->assertSuccessful();

        Exceptions::assertReported(RequestException::class);
    });
});
