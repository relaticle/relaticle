<?php

declare(strict_types=1);

use App\Support\MarketingNavigation;
use App\Support\NavItem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;

mutates(MarketingNavigation::class);

function extractNavRegion(string $html, string $ariaLabel): string
{
    preg_match('/<nav[^>]*aria-label="'.preg_quote($ariaLabel, '/').'"[^>]*>.*?<\/nav>/s', $html, $matches);

    return $matches[0] ?? '';
}

/** @return list<string> Icon names on this item and everything beneath it. */
function navIconNames(NavItem $item): array
{
    $names = $item->icon === null ? [] : [$item->icon];

    foreach ($item->children as $child) {
        $names = [...$names, ...navIconNames($child)];
    }

    return $names;
}

it('links every declared comparison and alternatives page from the footer', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();

    foreach (config('comparisons.compare') as $slug) {
        expect($html)->toContain(route('compare.show', ['competitor' => $slug]));
    }

    foreach (config('comparisons.alternatives') as $slug) {
        expect($html)->toContain(route('alternatives.show', ['competitor' => $slug]));
    }
});

it('names the assistant from config in both the header and the footer', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'Testbot'))->toBeGreaterThanOrEqual(3)
        ->and($html)->not->toContain('>Rela<');
});

it('renders the four footer columns with labelled navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(__('Product'))
        ->and($html)->toContain(__('Resources'))
        ->and($html)->toContain(__('Compare'))
        ->and($html)->toContain(__('Company'))
        ->and($html)->toContain('aria-label="'.__('Footer').'"');
});

it('links llms.txt and the GitHub repository from the footer', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(route('llms-txt'))
        ->and($html)->toContain('https://github.com/relaticle/relaticle');
});

it('renders the same items in desktop and mobile navigation', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-label="'.__('Main').'"')
        ->and($html)->toContain('aria-label="'.__('Mobile menu').'"')
        ->and(substr_count(extractNavRegion($html, __('Main')), route('pricing')))->toBe(1)
        ->and(substr_count(extractNavRegion($html, __('Mobile menu')), route('pricing')))->toBe(1);
});

it('opens the GitHub and Discord links from the header and mobile menu in a new tab', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    preg_match('/<header\b.*?<\/header>/s', $html, $header);
    preg_match_all('/<a[^>]*href="'.preg_quote(route('discord'), '/').'"[^>]*>/s', $header[0], $discordAnchors);

    expect($discordAnchors[0])->toHaveCount(2)
        ->each->toContain('rel="noopener noreferrer"');
});

it('keeps Discord out of the main navigation now that the header links it with a member count', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect(extractNavRegion($html, __('Main')))->not->toContain(route('discord'));
});

it('marks the current page in the navigation', function (): void {
    $html = $this->get('/pricing')->assertOk()->getContent();
    $header = extractNavRegion($html, __('Main'));

    expect($header)->not->toBeEmpty()
        ->and($header)->toContain('aria-current="page"');
});

it('renders product and resources dropdown groups with new page links', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('aria-expanded')
        ->and($html)->toContain('aria-haspopup="true"')
        ->and($html)->toContain(route('ai'))
        ->and($html)->toContain(route('selfHosted'))
        ->and($html)->toContain(__('Compare'));
});

it('draws a distinct icon for every name the navigation declares', function (): void {
    $icons = collect(app(MarketingNavigation::class)->header())
        ->flatMap(fn (NavItem $item): array => navIconNames($item))
        ->unique()
        ->values();

    // Every declared name has to resolve to its own `icons.*` component:
    // rendering throws on a missing one, and distinct output is what proves
    // two names don't share a drawing.
    $drawings = $icons->map(fn (string $name): string => Blade::render(
        '<x-dynamic-component :component="$component"/>',
        ['component' => 'icons.'.$name],
    ));

    expect($icons)->not->toBeEmpty()
        ->and($drawings->unique())->toHaveCount($icons->count());
});

it('shows the GitHub star and Discord member counts in the header', function (): void {
    Cache::put('github_stars_Relaticle_relaticle', 1517, 60);
    config()->set('services.discord.invite_url', 'https://discord.gg/abc123');
    Cache::put('discord_members_abc123', 157, 60);

    $html = $this->get('/')->assertOk()->getContent();
    preg_match('/<header\b.*?<\/header>/s', $html, $header);

    expect($header[0])->toContain('aria-label="GitHub, 1.5K stars"')
        ->and($header[0])->toContain('aria-label="Discord, 157 members"');
});

it('shows the GitHub star and Discord member counts in the help center header', function (): void {
    Cache::put('github_stars_Relaticle_relaticle', 1517, 60);
    config()->set('services.discord.invite_url', 'https://discord.gg/abc123');
    Cache::put('discord_members_abc123', 157, 60);

    $html = $this->get(route('help.index'))->assertOk()->getContent();
    preg_match('/<header\b.*?<\/header>/s', $html, $header);

    expect($header[0])->toContain('aria-label="GitHub, 1.5K stars"')
        ->and($header[0])->toContain('aria-label="Discord, 157 members"');
});

it('links GitHub and Discord without a count when the counts are unavailable', function (): void {
    $html = $this->get('/')->assertOk()->getContent();
    preg_match('/<header\b.*?<\/header>/s', $html, $header);

    expect($header[0])->toContain('aria-label="GitHub"')
        ->and($header[0])->toContain('aria-label="Discord"');
});

it('drops nav groups that feature flags emptied instead of rendering a dead link', function (): void {
    // With both content flags off, the Resources dropdown's "Learn" group has no
    // children and no url of its own, so an unfiltered group renders <a href="">.
    config()->set('relaticle.features.documentation', false);
    config()->set('relaticle.features.blog', false);
    Feature::purge();

    $groups = app(MarketingNavigation::class)->header();

    foreach ($groups as $group) {
        foreach ($group->children as $child) {
            expect($child->url !== null || $child->children !== [])->toBeTrue();
        }
    }
});

it('links the product pages from page copy, not only from the sitewide nav', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    // Header and footer links are boilerplate on every page. Strip both, so what
    // is left is a link a reader could actually follow out of the copy.
    $body = (string) preg_replace(['/<header[\s\S]*?<\/header>/', '/<footer[\s\S]*?<\/footer>/'], '', $html);

    expect($body)->toContain('href="'.route('ai').'"')
        ->and($body)->toContain('href="'.route('selfHosted').'"');
})->with(['/', '/pricing', '/compare/relaticle-vs-twenty', '/alternatives/attio']);

it('renders the works-with strip on the homepage with a link to the developer docs', function (): void {
    Cache::put('dockerhub_pulls_manukminasyan_relaticle', 21030, 60);

    $html = $this->get('/')->assertOk()->getContent();

    preg_match('/<section[^>]*aria-label="Works with"[^>]*>.*?<\/section>/s', $html, $matches);
    $strip = $matches[0] ?? '';

    expect($strip)->not->toBeEmpty()
        ->and($strip)->toContain('Claude', 'ChatGPT', 'Cursor', 'Gemini', '+ any MCP client')
        ->and($strip)->toContain('href="'.route('documentation.index').'"')
        ->and($strip)->toContain('21,000+');
});

it('renders the homepage without the product hunt launch badge', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('producthunt.com', 'Product Hunt');
});
