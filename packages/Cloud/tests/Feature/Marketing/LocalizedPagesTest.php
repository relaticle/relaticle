<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing;
use App\Features\EmailIntegration;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Http\Middleware\SetPublicLocale;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
use Relaticle\Ink\Models\Post;
use Spatie\Honeypot\EncryptedTime;
use Symfony\Component\DomCrawler\Crawler;

mutates(SetPublicLocale::class, LocalizedUrl::class);

beforeEach(function (): void {
    Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 42], 200)]);
    Feature::define(EmailIntegration::class, true);
});

dataset('public paths', [
    '/',
    '/pricing',
    '/press',
    '/ai',
    '/ai-native-crm',
    '/self-hosted',
    '/crm-for-claude',
    '/crm-for-chatgpt',
    '/security',
    '/email-and-calendar',
    '/contact',
    '/privacy-policy',
    '/terms-of-service',
    '/compare/relaticle-vs-twenty',
    '/alternatives/attio',
    '/help',
    '/help/getting-started',
    '/help/getting-started/create-your-first-company',
    '/developers',
    '/developers/mcp',
]);

dataset('prefixes', ['de', 'es', 'fr']);

function publicPageUrl(string $locale, string $path): string
{
    return $path === '/' ? "/{$locale}" : "/{$locale}{$path}";
}

function hreflangLinks(string $html): array
{
    $links = [];

    (new Crawler($html))->filter('link[rel="alternate"][hreflang]')->each(function (Crawler $link) use (&$links): void {
        $links[$link->attr('hreflang')] = $link->attr('href');
    });

    return $links;
}

it('serves every public page under each prefix', function (string $path, string $locale): void {
    $this->get(publicPageUrl($locale, $path))->assertOk();
})->with('public paths')->with('prefixes');

it('keeps every English URL unchanged', function (): void {
    $expected = [
        'pricing' => '/pricing',
        'press' => '/press',
        'ai' => '/ai',
        'aiNativeCrm' => '/ai-native-crm',
        'selfHosted' => '/self-hosted',
        'security' => '/security',
        'emailAndCalendar' => '/email-and-calendar',
        'contact' => '/contact',
        'terms.show' => '/terms-of-service',
        'policy.show' => '/privacy-policy',
        'assistants.claude' => '/crm-for-claude',
        'assistants.chatgpt' => '/crm-for-chatgpt',
        'help.index' => '/help',
        'documentation.index' => '/developers',
        'home' => '/',
    ];

    foreach ($expected as $name => $path) {
        expect(route($name, absolute: false))->toBe($path);
    }

    expect(route('compare.show', ['competitor' => 'twenty'], false))->toBe('/compare/relaticle-vs-twenty')
        ->and(route('help.show', ['category' => 'import', 'slug' => 'x'], false))->toBe('/help/import/x');
});

it('serves /de and /de/ as the German home', function (string $path): void {
    $this->get($path)->assertOk()->assertSee('lang="de"', false);
})->with(['/de', '/de/']);

it('does not treat /developers as a German URL', function (): void {
    $route = Route::getRoutes()->match(Request::create('/developers'));

    expect($route->getName())->toBe('documentation.index');

    $this->get('/developers')->assertOk()->assertSee('lang="en"', false);
});

it('emits hreflang for all four languages plus x-default and a self canonical', function (): void {
    $html = (string) $this->get('/de/pricing')->assertOk()->getContent();

    expect(hreflangLinks($html))->toBe([
        'en' => url('/pricing'),
        'de' => url('/de/pricing'),
        'es' => url('/es/pricing'),
        'fr' => url('/fr/pricing'),
        'x-default' => url('/pricing'),
    ]);

    $canonical = (new Crawler($html))->filter('link[rel="canonical"]')->attr('href');
    expect($canonical)->toBe(url('/de/pricing'));
});

it('formats the home page mock dates and amounts in the page language', function (): void {
    $this->get('/')->assertOk()->assertSee('Aug 24, 2026')->assertSee('$18,000.00')->assertSee('$48,000');

    $this->get('/de')->assertOk()
        ->assertSee('24. Aug 2026')->assertSee('18.000,00')
        ->assertDontSee('Aug 24, 2026')->assertDontSee('$18,000.00');

    $this->get('/fr')->assertOk()->assertSee('24 août 2026')->assertDontSee('Aug 24, 2026');
});

it('points hreflang and the switcher at the same docs page the reader is on', function (string $path): void {
    $html = (string) $this->get("/de{$path}")->assertOk()->getContent();
    $switcher = (new Crawler($html))->filter('footer [data-language-switcher] a[hreflang]');

    expect(hreflangLinks($html))->toBe([
        'en' => url($path),
        'de' => url("/de{$path}"),
        'es' => url("/es{$path}"),
        'fr' => url("/fr{$path}"),
        'x-default' => url($path),
    ])->and($switcher->each(fn (Crawler $a): string => $a->attr('href')))->toBe([
        url($path),
        url("/de{$path}"),
        url("/es{$path}"),
        url("/fr{$path}"),
    ]);
})->with(['/developers/mcp', '/developers/rest-api', '/help/import/prepare-your-csv']);

it('builds alternates without leaking route defaults into the query string', function (): void {
    $html = (string) $this->get('/fr/crm-for-claude')->assertOk()->getContent();

    expect(hreflangLinks($html)['es'])->toBe(url('/es/crm-for-claude'));
});

it('sets html lang and og:locale from the prefix', function (): void {
    $german = new Crawler((string) $this->get('/de/pricing')->getContent());
    $english = new Crawler((string) $this->get('/pricing')->getContent());

    expect($german->filter('html')->attr('lang'))->toBe('de')
        ->and($german->filter('meta[property="og:locale"]')->attr('content'))->toBe('de_DE')
        ->and($english->filter('html')->attr('lang'))->toBe('en')
        ->and($english->filter('meta[property="og:locale"]')->attr('content'))->toBe('en_US');
});

function nonLocalizedPublicPath(string $path): bool
{
    return in_array($path, ['/login', '/register', '/discord', '/llms.txt', '/openapi.json', '/openapi.yaml', '/developers/api', '/.well-known/security.txt', '/security.txt'], true)
        || Str::startsWith($path, ['/blog', '/brand/', '/images/', '/build/', '/storage/', '/app', '/auth']);
}

function unlocalizedHrefsOn(string $html, string $locale): array
{
    $host = parse_url(url('/'), PHP_URL_HOST);

    return collect((new Crawler($html))->filter('a[href]:not([hreflang])')->each(fn (Crawler $a): string => (string) $a->attr('href')))
        ->filter(fn (string $href): bool => $href !== '' && ! Str::startsWith($href, ['#', '?', 'mailto:', 'tel:', 'javascript:']))
        ->map(fn (string $href): array => parse_url($href) ?: [])
        ->filter(fn (array $parts): bool => ($parts['host'] ?? $host) === $host)
        ->map(fn (array $parts): string => $parts['path'] ?? '/')
        ->reject(fn (string $path): bool => $path === "/{$locale}" || Str::startsWith($path, "/{$locale}/") || nonLocalizedPublicPath($path))
        ->unique()
        ->values()
        ->all();
}

it('keeps every link on every localized public page German', function (): void {
    $paths = [
        '/', '/pricing', '/press', '/ai', '/ai-native-crm', '/self-hosted', '/crm-for-claude', '/crm-for-chatgpt', '/security',
        '/email-and-calendar', '/contact', '/privacy-policy', '/terms-of-service', '/help', '/help/getting-started',
        '/help/getting-started/create-your-first-company', '/developers', '/developers/mcp',
        ...array_map(fn (string $slug): string => "/compare/relaticle-vs-{$slug}", config('comparisons.compare')),
        ...array_map(fn (string $slug): string => "/alternatives/{$slug}", config('comparisons.alternatives')),
    ];

    $offenders = [];

    foreach ($paths as $path) {
        $html = (string) $this->get(publicPageUrl('de', $path))->assertOk()->getContent();

        foreach (unlocalizedHrefsOn($html, 'de') as $href) {
            $offenders[] = "{$path} -> {$href}";
        }
    }

    expect($offenders)->toBe([]);
});

it('links the API reference at its one English URL from every localized developer page', function (string $locale): void {
    $html = (string) $this->get("/{$locale}/developers/mcp")->assertOk()->getContent();
    $hrefs = (new Crawler($html))->filter('a[href]')->each(fn (Crawler $a): string => (string) $a->attr('href'));

    expect($hrefs)->toContain(url('/developers/api'))
        ->and($hrefs)->not->toContain(url("/{$locale}/developers/api"));

    $this->get('/developers/api')->assertOk();
})->with(['de', 'es', 'fr']);

it('leaves a link to the unprefixed-only API reference English', function (): void {
    app()->setLocale('de');

    expect(LocalizedUrl::localizeLinks('<a href="/developers/api">x</a><a href="/developers/mcp">y</a>'))
        ->toBe('<a href="/developers/api">x</a><a href="/de/developers/mcp">y</a>');
});

it('groups the credits on a localized pricing page the way that language does', function (string $locale): void {
    Feature::for(null)->activate(Billing::class);

    $each = Locales::within($locale, fn (): string => __(':credits credits each month', ['credits' => Number::format(Plan::Pro->credits(), locale: $locale)]));

    $this->get("/{$locale}/pricing")->assertOk()->assertSee($each, false);
})->with(['de', 'fr']);

it('groups the credits of the Spanish pricing page the Latin American way', function (): void {
    Feature::for(null)->activate(Billing::class);

    $this->get('/es/pricing')->assertOk()->assertSee('2,000 créditos', false);
});

it('keeps English grouping on the English pricing page', function (): void {
    Feature::for(null)->activate(Billing::class);

    $this->get('/pricing')->assertOk()->assertSee('2,000 credits each month', false);
});

it('words the own-your-data heading of the security page as moving away, not leaving a workspace', function (string $locale, string $heading): void {
    $this->get("/{$locale}/security")->assertOk()->assertSeeInOrder([$heading, 'AGPL-3.0'], false);
})->with([['de', '>Wechseln<'], ['es', '>Migrar<'], ['fr', '>Partir<']]);

it('sets the locale cookie', function (): void {
    $this->get('/de/pricing')->assertCookie(Locales::COOKIE, 'de', false);
    $this->get('/pricing')->assertCookie(Locales::COOKIE, 'en', false);
});

it('keeps /pricing, /help and /blog English for a German cookie, browser and signed-in user', function (string $path): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->withUnencryptedCookie(Locales::COOKIE, 'de')
        ->get($path, ['Accept-Language' => 'de-DE,de;q=0.9'])
        ->assertOk()
        ->assertSee('<html lang="en"', false);
})->with(['/pricing', '/help', '/blog']);

it('carries the page language into the Context of a public request', function (): void {
    $this->withUnencryptedCookie(Locales::COOKIE, 'fr')->get('/pricing');
    expect(Context::getHidden(Locales::CONTEXT_KEY))->toBe('en');

    $this->get('/de/pricing');
    expect(Context::getHidden(Locales::CONTEXT_KEY))->toBe('de');
});

it('serves the German Markdown response under /de', function (): void {
    app('translator')->addLines(['*.Companies' => 'Unternehmen'], 'de');

    $german = $this->get('/de/press', ['Accept' => 'text/markdown'])->assertOk()->getContent();
    $english = $this->get('/press', ['Accept' => 'text/markdown'])->assertOk()->getContent();

    expect($german)->toContain('Unternehmen')
        ->and($english)->not->toContain('Unternehmen');
});

it('serves a localized page while the app panel has its own domain and only known hosts are trusted', function (string $path): void {
    Route::domain('app.relaticle.test')->get('/{tenant}', fn (): string => '');
    Request::setTrustedHosts(['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$', '^app\.relaticle\.test$']);

    $this->get("/de{$path}")->assertOk();
})->with(['/privacy-policy', '/terms-of-service', '/developers/rest-api']);

it('links to the English URL when a route has no localized sibling', function (): void {
    expect(LocalizedUrl::route('login', locale: 'de'))->toBe(route('login'))
        ->and(LocalizedUrl::route('pricing', locale: 'de'))->toBe(url('/de/pricing'))
        ->and(LocalizedUrl::route('pricing', locale: 'en'))->toBe(url('/pricing'));
});

it('renders the language switcher with every language and a check on the current one', function (): void {
    $html = (string) $this->get('/de/pricing')->assertOk()->getContent();
    $switcher = (new Crawler($html))->filter('footer [data-language-switcher]');

    expect($switcher)->toHaveCount(1);

    $items = $switcher->filter('a[hreflang]');

    expect($items->each(fn (Crawler $a): string => $a->attr('hreflang')))->toBe(['en', 'de', 'es', 'fr'])
        ->and($items->eq(1)->attr('href'))->toBe(url('/de/pricing'))
        ->and($items->eq(1)->attr('aria-current'))->toBe('true')
        ->and($items->eq(0)->attr('href'))->toBe(url('/pricing'))
        ->and($items->eq(1)->text())->toContain('Deutsch')->toContain('German');
});

it('renders the language switcher in the docs shell', function (): void {
    $html = (string) $this->get('/help')->assertOk()->getContent();

    expect((new Crawler($html))->filter('footer [data-language-switcher]'))->toHaveCount(1);
});

it('does not suggest the localized home page on a German 404', function (): void {
    $html = $this->withUnencryptedCookie(Locales::COOKIE, 'de')
        ->get('/de/help/import/missing', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->getContent();

    expect($html)->not->toContain('You were probably looking for Features');
});

it('renders a mistyped German URL as a German 404', function (): void {
    app('translator')->addLines(['*.Page not found' => 'Seite nicht gefunden'], 'de');

    $english = (string) $this->get('/pricng', ['Accept' => 'text/html'])->assertNotFound()->getContent();
    $german = (string) $this->get('/de/pricng', ['Accept' => 'text/html'])->assertNotFound()->getContent();

    expect($german)->toContain('<html lang="de"')->toContain('Seite nicht gefunden')
        ->and($german)->toContain('You were probably looking for Pricing')
        ->and($english)->toContain('<html lang="en"')->not->toContain('Seite nicht gefunden');
});

it('keeps an unprefixed path that starts like a language English on a 404', function (): void {
    $this->get('/developers/zz-missing', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertSee('<html lang="en"', false);
});

function contactSubmission(array $overrides = []): array
{
    config(['honeypot.enabled' => true]);

    return [
        'my_name' => '',
        'valid_from' => (string) EncryptedTime::create(now()->subMinute()),
        'name' => 'Jane Doe',
        'email' => 'jane@gmail.com',
        'company' => 'Acme Inc',
        'message' => 'I would like to learn more about your enterprise plan and integrations.',
        ...$overrides,
    ];
}

it('posts the contact form under its own language and redirects back to it', function (): void {
    Mail::fake();
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->post('/de/contact', contactSubmission())
        ->assertRedirect('/de/contact')
        ->assertSessionHas('success');

    $this->actingAs($user)
        ->post('/contact', contactSubmission())
        ->assertRedirect('/contact');
});

it('validates the German contact form in German whatever the profile language is', function (): void {
    Mail::fake();
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->from('/de/contact')
        ->post('/de/contact', contactSubmission(['email' => 'not-an-email']))
        ->assertRedirect('/de/contact')
        ->assertSessionHasErrors(['email' => 'E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.']);

    $form = (string) $this->get('/de/contact')->getContent();

    expect((new Crawler($form))->filter('form[method="POST"]')->attr('action'))->toBe(url('/de/contact'))
        ->and((string) (new Crawler((string) $this->get('/contact')->getContent()))->filter('form[method="POST"]')->attr('action'))->toBe(url('/contact'));
});

it('keeps the page number in the alternates of a paginated page', function (): void {
    Post::factory()->published()->count(40)->create();

    $html = (string) $this->get('/blog?page=2')->assertOk()->getContent();
    $page = (new Crawler($html))->filter('link[rel="canonical"]')->attr('href');

    expect($page)->toContain('page=2')
        ->and(hreflangLinks($html)['x-default'])->toBe($page)
        ->and(hreflangLinks($html)['en'])->toBe($page);

    $first = (string) $this->get('/blog')->assertOk()->getContent();

    expect(hreflangLinks($first)['x-default'])->toBe(url('/blog'));
});

it('keeps a page number out of the alternates of a page that does not paginate', function (): void {
    $html = (string) $this->get('/de/pricing?page=2')->assertOk()->getContent();

    expect(hreflangLinks($html)['de'])->toBe(url('/de/pricing'))
        ->and(hreflangLinks($html)['x-default'])->toBe(url('/pricing'));
});

it('lets the route parameters win over a query key of the same name on the switcher', function (): void {
    $html = (string) $this->get('/de/compare/relaticle-vs-twenty?competitor=hubspot')->assertOk()->getContent();
    $links = (new Crawler($html))->filter('[data-language-switcher] a[hreflang]');

    expect($links->eq(0)->attr('href'))->toStartWith(url('/compare/relaticle-vs-twenty'));
});

it('keeps the query string on the language switcher links but not on hreflang', function (): void {
    $html = (string) $this->get('/de/contact?plan=enterprise')->assertOk()->getContent();
    $links = (new Crawler($html))->filter('[data-language-switcher] a[hreflang]');

    expect($links->eq(3)->attr('href'))->toBe(url('/fr/contact').'?plan=enterprise')
        ->and($links->eq(0)->attr('href'))->toBe(url('/contact').'?plan=enterprise')
        ->and(hreflangLinks($html)['fr'])->toBe(url('/fr/contact'));
});

it('renders the language switcher as an accessible disclosure', function (): void {
    $html = (string) $this->get('/de/pricing')->assertOk()->getContent();
    $switcher = (new Crawler($html))->filter('footer [data-language-switcher]');
    $button = $switcher->filter('button');
    $list = $switcher->filter('nav');

    expect($button->attr('aria-label'))->toBeNull()
        ->and($button->attr('aria-haspopup'))->toBeNull()
        ->and($button->attr('aria-controls'))->toBe($list->attr('id'))
        ->and($button->attr('x-bind:aria-expanded'))->toBe('open')
        ->and($list->attr('aria-label'))->not->toBeNull()
        ->and($switcher->attr('x-on:focusout'))->toContain('relatedTarget')
        ->and($switcher->filter('a[lang]'))->toHaveCount(0)
        ->and($switcher->filter('a span[lang]'))->toHaveCount(4)
        ->and($button->filter('span')->text())->toBe('Deutsch');
});

dataset('marketing paths', [
    '/', '/pricing', '/press', '/ai', '/ai-native-crm', '/self-hosted', '/crm-for-claude', '/crm-for-chatgpt', '/security',
    '/email-and-calendar', '/contact', '/compare/relaticle-vs-twenty', '/compare/relaticle-vs-espocrm',
    '/alternatives/attio', '/alternatives/hubspot',
]);

function translatedCataloguePatterns(string $locale): array
{
    $english = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    $translated = json_decode((string) file_get_contents(dirname(__DIR__, 3)."/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
    $patterns = [];

    foreach (array_keys($english) as $key) {
        if (($translated[$key] ?? $key) !== $key && preg_match('/[A-Za-z]{3}/', (string) preg_replace('/:\w+/', '', $key)) === 1) {
            $patterns[$key] = '/^'.preg_replace('/\\:\w+/', '.+', preg_quote(Str::squish($key), '/')).'$/su';
        }
    }

    return $patterns;
}

function renderedTexts(string $html): array
{
    $crawler = new Crawler($html);
    $texts = $crawler->filterXPath('//body//text()[not(ancestor::script) and not(ancestor::style)]')->each(fn (Crawler $node): string => $node->text());
    $attributes = $crawler->filterXPath('//*[@aria-label or @placeholder or @title or @alt or @content]')->each(
        fn (Crawler $node): array => array_filter([$node->attr('aria-label'), $node->attr('placeholder'), $node->attr('title'), $node->attr('alt'), $node->attr('content')]),
    );

    return array_values(array_unique(array_filter(array_map(Str::squish(...), [...$texts, ...array_merge(...$attributes)]))));
}

it('renders no catalogue English on a German page', function (string $path): void {
    $this->travelTo('2026-11-13 10:00:00');

    $texts = renderedTexts((string) $this->get(publicPageUrl('de', $path))->assertOk()->getContent());
    $leaks = [];

    foreach (translatedCataloguePatterns('de') as $key => $pattern) {
        foreach ($texts as $text) {
            if (preg_match($pattern, $text) === 1) {
                $leaks[] = $key;
            }
        }
    }

    expect(array_values(array_unique($leaks)))->toBe([]);
})->with('marketing paths');

it('shows the English-governs notice on translated legal pages', function (string $path): void {
    $notice = fn (string $url): int => (new Crawler((string) $this->get($url)->assertOk()->getContent()))->filter('[data-legal-notice]')->count();

    expect($notice($path))->toBe(0);

    foreach (['de', 'es', 'fr'] as $locale) {
        expect($notice(publicPageUrl($locale, $path)))->toBe(1);
    }
})->with(['/privacy-policy', '/terms-of-service']);
