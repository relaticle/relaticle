<?php

declare(strict_types=1);

namespace App\Services\Favicon\Drivers;

use App\Services\Favicon\SsrfGuard;
use AshAllenDesign\FaviconFetcher\Collections\FaviconCollection;
use AshAllenDesign\FaviconFetcher\Concerns\HasDefaultFunctionality;
use AshAllenDesign\FaviconFetcher\Concerns\MakesHttpRequests;
use AshAllenDesign\FaviconFetcher\Concerns\ValidatesUrls;
use AshAllenDesign\FaviconFetcher\Contracts\Fetcher;
use AshAllenDesign\FaviconFetcher\Exceptions\InvalidUrlException;
use AshAllenDesign\FaviconFetcher\Favicon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class HighQualityDriver implements Fetcher
{
    use HasDefaultFunctionality;
    use MakesHttpRequests;
    use ValidatesUrls;

    /**
     * Attempt to fetch high-quality favicon using a waterfall strategy.
     *
     * Strategy:
     * 1. Try direct HTML parsing for Apple Touch icons (180x180)
     * 2. Try direct HTML parsing for high-res PNG favicons
     * 3. Fall back to Google with 256px size parameter
     * 4. Fall back to DuckDuckGo
     */
    public function fetch(string $url): ?Favicon
    {
        throw_unless($this->urlIsValid($url), InvalidUrlException::class, $url.' is not a valid URL');

        if (! SsrfGuard::isAllowed($url)) {
            return null;
        }

        if ($this->useCache && $favicon = $this->attemptToFetchFromCache($url)) {
            return $favicon;
        }

        $favicon = $this->tryAppleTouchIcon($url);
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->tryHighResFavicon($url);
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->tryGoogleHighRes($url);
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        return $this->tryDuckDuckGo($url);
    }

    public function fetchAll(string $url): FaviconCollection
    {
        throw new \Exception('fetchAll not supported by HighQualityDriver');
    }

    /**
     * The favicon-fetcher HTTP client, hardened so every redirect hop is
     * re-validated against {@see SsrfGuard} (SSRF, CWE-918).
     */
    private function guardedHttpClient(): PendingRequest
    {
        return $this->httpClient()->withOptions(SsrfGuard::redirectGuardOptions());
    }

    private function tryAppleTouchIcon(string $url): ?Favicon
    {
        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($url)
            );

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            $appleTouchPatterns = [
                '/<link\b[^>]*\brel=["\']apple-touch-icon["\'][^>]*\bhref=["\']([^"\']+)["\']/i',
                '/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']apple-touch-icon["\']/i',
            ];

            $matchedIconUrl = null;
            foreach ($appleTouchPatterns as $appleTouchPattern) {
                if (preg_match($appleTouchPattern, (string) $html, $matches)) {
                    $matchedIconUrl = $matches[1];
                    break;
                }
            }

            if ($matchedIconUrl !== null) {
                $iconUrl = $this->convertToAbsoluteUrl($url, $matchedIconUrl);

                return new Favicon(
                    url: $url,
                    faviconUrl: $iconUrl,
                    fromDriver: $this,
                )->setIconType(Favicon::TYPE_APPLE_TOUCH_ICON)
                    ->setIconSize(180);
            }

            $iconUrl = $this->stripPathFromUrl($url).'/apple-touch-icon.png';
            /** @var Response $testResponse */
            $testResponse = $this->guardedHttpClient()->head($iconUrl);

            if ($testResponse->successful()) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $iconUrl,
                    fromDriver: $this,
                )->setIconType(Favicon::TYPE_APPLE_TOUCH_ICON)
                    ->setIconSize(180);
            }

        } catch (\Exception) {
            // Fall through to next strategy
        }

        return null;
    }

    private function tryHighResFavicon(string $url): ?Favicon
    {
        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($url)
            );

            if (! $response->successful()) {
                return null;
            }

            $html = (string) $response->body();

            foreach ([512, 256, 192] as $size) {
                $patterns = [
                    "/<link\\b[^>]*\\bsizes=[\"']{$size}x{$size}[\"'][^>]*\\bhref=[\"']([^\"']+)[\"']/i",
                    "/<link\\b[^>]*\\bhref=[\"']([^\"']+)[\"'][^>]*\\bsizes=[\"']{$size}x{$size}[\"']/i",
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $matches)) {
                        return new Favicon(
                            url: $url,
                            faviconUrl: $this->convertToAbsoluteUrl($url, $matches[1]),
                            fromDriver: $this,
                        )->setIconSize($size);
                    }
                }
            }

        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    private function tryGoogleHighRes(string $url): ?Favicon
    {
        try {
            $urlWithoutProtocol = str_replace(['https://', 'http://'], '', $url);

            $faviconUrl = 'https://www.google.com/s2/favicons?sz=256&domain='.$urlWithoutProtocol;

            if (! SsrfGuard::isAllowed($faviconUrl)) {
                return null;
            }

            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($faviconUrl)
            );

            if ($response->successful()) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $faviconUrl,
                    fromDriver: $this,
                )->setIconSize(256);
            }

        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    private function tryDuckDuckGo(string $url): ?Favicon
    {
        $urlWithoutProtocol = str_replace(['https://', 'http://'], '', $url);
        $faviconUrl = 'https://icons.duckduckgo.com/ip3/'.$urlWithoutProtocol.'.ico';

        if (! SsrfGuard::isAllowed($faviconUrl)) {
            return null;
        }

        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($faviconUrl)
            );

            if ($response->successful()) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $faviconUrl,
                    fromDriver: $this,
                );
            }

        } catch (\Exception) {
            // Fall through
        }

        return $this->notFound($url);
    }

    private function faviconIsAccessible(Favicon $favicon): bool
    {
        $faviconUrl = $favicon->getFaviconUrl();

        if (! SsrfGuard::isAllowed($faviconUrl)) {
            return false;
        }

        try {
            /** @var Response $response */
            $response = $this->guardedHttpClient()->head($faviconUrl);

            return $response->successful();
        } catch (\Exception) {
            return false;
        }
    }

    private function convertToAbsoluteUrl(string $baseUrl, string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';

        if (str_starts_with($path, '//')) {
            return $scheme.':'.$path;
        }

        if (str_starts_with($path, '/')) {
            return $scheme.'://'.$host.$path;
        }

        return $scheme.'://'.$host.'/'.ltrim($path, '/');
    }

    private function stripPathFromUrl(string $url): string
    {
        $parsedUrl = parse_url($url);

        if ($parsedUrl === false || ! isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            return $url;
        }

        $result = $parsedUrl['scheme'].'://'.$parsedUrl['host'];

        if (isset($parsedUrl['port'])) {
            $result .= ':'.$parsedUrl['port'];
        }

        return $result;
    }
}
