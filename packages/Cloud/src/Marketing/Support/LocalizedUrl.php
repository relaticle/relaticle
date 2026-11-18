<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Locale;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class LocalizedUrl
{
    public const string DEFAULT_LOCALE = 'en';

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(string $name, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= app()->getLocale();

        return route(self::nameIn($name, $locale) ?? $name, $parameters, $absolute);
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
            fn (array $match): string => self::servesLocalizedPath("/{$locale}{$match[1]}", $locale)
                ? 'href="/'.$locale.$match[1].'"'
                : $match[0],
            $html,
        ) ?? $html;
    }

    private static function servesLocalizedPath(string $path, string $locale): bool
    {
        try {
            $route = Route::getRoutes()->match(Request::create((string) parse_url($path, PHP_URL_PATH)));
        } catch (HttpExceptionInterface) {
            return false;
        }

        return str_starts_with((string) $route->getName(), "{$locale}.");
    }

    /** @return array<string, int> */
    private static function pageQuery(): array
    {
        $page = request()->query('page');

        return is_numeric($page) && (int) $page > 1 ? ['page' => (int) $page] : [];
    }

    /** @param  array<string, mixed>  $query */
    private static function siblingIn(string $locale, array $query = []): ?string
    {
        $route = Route::current();
        $name = $route instanceof RoutingRoute ? $route->getName() : null;
        $localizedName = $name === null ? null : self::nameIn($name, $locale);

        return $route === null || $localizedName === null
            ? null
            : route($localizedName, [...Arr::only($route->parameters(), $route->parameterNames()), ...$query]);
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

    private static function baseName(string $name): string
    {
        $locales = implode('|', array_diff(config('app.available_locales'), [self::DEFAULT_LOCALE]));

        return preg_replace(["/^(?:{$locales})\\./", "/^blog\\.(?:{$locales})\\./"], ['', 'blog.'], $name, 1) ?? $name;
    }
}
