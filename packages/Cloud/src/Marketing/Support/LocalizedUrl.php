<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Locale;
use Relaticle\Ink\Models\Post;

final readonly class LocalizedUrl
{
    public const string DEFAULT_LOCALE = 'en';

    // The listings whose canonical keeps `?page` (blog/index.blade.php).
    private const array PAGINATED_ROUTES = ['blog.index', 'blog.category', 'blog.tag'];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();

        return route(self::nameIn($name, $locale) ?? self::baseName($name), $parameters, $absolute);
    }

    /** @return array<string, string> */
    public static function alternates(): array
    {
        $alternates = [];

        foreach (config('app.available_locales') as $locale) {
            $url = self::siblingIn($locale, self::pageQuery());

            if ($url !== null) {
                $alternates[$locale] = $url;
            }
        }

        return isset($alternates[self::DEFAULT_LOCALE])
            ? [...$alternates, 'x-default' => $alternates[self::DEFAULT_LOCALE]]
            : [];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    public static function alternatesOf(string $name, array $parameters = []): array
    {
        $alternates = [];

        foreach (config('app.available_locales') as $locale) {
            $localizedName = self::nameIn($name, $locale);

            if ($localizedName !== null) {
                $alternates[$locale] = route($localizedName, $parameters);
            }
        }

        return isset($alternates[self::DEFAULT_LOCALE])
            ? [...$alternates, 'x-default' => $alternates[self::DEFAULT_LOCALE]]
            : [];
    }

    public static function current(string $locale): string
    {
        return self::siblingIn($locale, request()->query()) ?? self::route('home', locale: $locale);
    }

    public static function localeOfPath(string $path): ?string
    {
        $segment = explode('/', trim($path, '/'))[0];

        return in_array($segment, array_diff(config('app.available_locales'), [self::DEFAULT_LOCALE]), true) ? $segment : null;
    }

    public static function localizeLinks(string $html): string
    {
        $locale = app()->getLocale();

        if ($locale === self::DEFAULT_LOCALE) {
            return $html;
        }

        return preg_replace_callback(
            '/href="(\/(?!\/)[^"]*)"/',
            fn (array $match): string => self::servesLocalizedPath($match[1], $locale)
                ? 'href="/'.$locale.$match[1].'"'
                : $match[0],
            $html,
        ) ?? $html;
    }

    private static function servesLocalizedPath(string $path, string $locale): bool
    {
        $name = self::routeNameOf($path);

        return $name !== null && Route::has("{$locale}.{$name}");
    }

    public static function baseRouteNameOf(string $path): ?string
    {
        $name = self::routeNameOf($path);

        return $name === null ? null : self::baseName($name);
    }

    private static function routeNameOf(string $path): ?string
    {
        $request = Request::create(url((string) parse_url($path, PHP_URL_PATH)));
        $fallback = null;

        foreach (Route::getRoutes()->get($request->getMethod()) as $route) {
            if (! $route->matches($request)) {
                continue;
            }

            if (! $route->isFallback) {
                return $route->getName();
            }

            $fallback ??= $route;
        }

        return $fallback?->getName();
    }

    /** @return array<string, int> */
    private static function pageQuery(): array
    {
        $page = request()->query('page');
        $name = Route::current()?->getName();
        $paginates = $name !== null && in_array(self::baseName($name), self::PAGINATED_ROUTES, true);

        return $paginates && is_numeric($page) && (int) $page > 1 ? ['page' => (int) $page] : [];
    }

    /** @param  array<string, mixed>  $query */
    private static function siblingIn(string $locale, array $query = []): ?string
    {
        $route = Route::current();
        $name = $route?->getName();

        if ($name !== null && self::baseName($name) === 'blog.show') {
            return self::currentPostUrls()[$locale] ?? null;
        }

        $localizedName = $name === null ? null : self::nameIn($name, $locale);

        if ($route === null || $localizedName === null) {
            return null;
        }

        if ($locale !== app()->getLocale() && ! self::listingReaches($locale, $route, $query)) {
            return null;
        }

        return route($localizedName, [...$query, ...Arr::only($route->parameters(), $route->parameterNames())]);
    }

    /** @param  array<string, mixed>  $query */
    private static function listingReaches(string $locale, RoutingRoute $route, array $query): bool
    {
        $baseName = self::baseName((string) $route->getName());
        $page = is_numeric($query['page'] ?? null) ? max((int) $query['page'], 1) : 1;

        if (! in_array($baseName, self::PAGINATED_ROUTES, true) || ($baseName === 'blog.index' && $page === 1)) {
            return true;
        }

        $slug = $route->parameter('slug');

        return self::listingPostCount($locale, $baseName, is_string($slug) ? $slug : null) > ($page - 1) * (int) config('ink.per_page', 12);
    }

    private static function listingPostCount(string $locale, string $baseName, ?string $slug): int
    {
        $attributes = request()->attributes;
        $key = "blog.counts.{$baseName}.{$slug}";

        if (! $attributes->has($key)) {
            $attributes->set($key, Post::query()
                ->published()
                ->when($baseName === 'blog.category', fn (Builder $posts) => $posts->whereRelation('category', 'slug', $slug))
                ->when($baseName === 'blog.tag', fn (Builder $posts) => $posts->whereRelation('tags', 'slug', $slug))
                ->toBase()
                ->selectRaw('locale, count(*) as aggregate')
                ->groupBy('locale')
                ->pluck('aggregate', 'locale')
                ->all());
        }

        return (int) ($attributes->get($key)[$locale] ?? 0);
    }

    /** @return array<string, array{native: string, english: string}> */
    public static function languages(): array
    {
        $languages = [];

        foreach (config('app.available_locales') as $locale) {
            $languages[$locale] = [
                'native' => mb_ucfirst(Locale::getDisplayLanguage($locale, $locale)),
                'english' => Locale::getDisplayLanguage($locale, 'en'),
            ];
        }

        return $languages;
    }

    public static function openGraphLocale(string $locale): string
    {
        return match ($locale) {
            'de' => 'de_DE',
            'es' => 'es_LA',
            'fr' => 'fr_FR',
            default => 'en_US',
        };
    }

    private static function nameIn(string $baseName, string $locale): ?string
    {
        $baseName = self::baseName($baseName);

        if ($locale === self::DEFAULT_LOCALE) {
            return Route::has($baseName) ? $baseName : null;
        }

        if (str_starts_with($baseName, 'blog.') && ! in_array($locale, self::blogLocales(), true)) {
            return null;
        }

        $candidates = [
            "{$locale}.{$baseName}",
            str_starts_with($baseName, 'blog.') ? 'blog.'.$locale.'.'.substr($baseName, 5) : null,
        ];

        foreach (array_filter($candidates) as $candidate) {
            if (Route::has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function hasBlogIn(string $locale): bool
    {
        return $locale === self::DEFAULT_LOCALE || in_array($locale, self::blogLocales(), true);
    }

    /** @return list<string> */
    private static function blogLocales(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has('blog.locales')) {
            $attributes->set('blog.locales', Post::query()->published()->distinct()->pluck('locale')->all());
        }

        return $attributes->get('blog.locales');
    }

    /** @return array<string, string> */
    private static function currentPostUrls(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has('blog.post_urls')) {
            $attributes->set('blog.post_urls', self::postUrls());
        }

        return $attributes->get('blog.post_urls');
    }

    /** @return array<string, string> */
    private static function postUrls(): array
    {
        $post = Post::query()
            ->published()
            ->inLocale(app()->getLocale())
            ->where('slug', Route::current()?->parameter('slug'))
            ->first();

        if (! $post instanceof Post) {
            return [];
        }

        return [
            (string) $post->getAttribute('locale') => $post->getUrl(),
            ...$post->translations()->mapWithKeys(fn (Post $translation): array => [(string) $translation->getAttribute('locale') => $translation->getUrl()])->all(),
        ];
    }

    private static function baseName(string $name): string
    {
        $locales = implode('|', array_diff(config('app.available_locales'), [self::DEFAULT_LOCALE]));

        return preg_replace(["/^(?:{$locales})\\./", "/^blog\\.(?:{$locales})\\./"], ['', 'blog.'], $name, 1) ?? $name;
    }
}
