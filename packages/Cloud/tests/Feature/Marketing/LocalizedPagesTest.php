<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Marketing\Http\Middleware\SetPublicLocale;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
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

it('keeps links on a German page German', function (string $path): void {
    $html = (string) $this->get('/de/pricing')->assertOk()->getContent();
    $hrefs = (new Crawler($html))->filter('a[href]:not([hreflang])')->each(fn (Crawler $a): string => rtrim((string) $a->attr('href'), '/'));

    expect($hrefs)->not->toContain(rtrim(url($path), '/'));
})->with(['/', '/pricing', '/security', '/contact', '/privacy-policy', '/terms-of-service', '/ai', '/self-hosted', '/help', '/developers', '/ai-native-crm', '/press']);

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
