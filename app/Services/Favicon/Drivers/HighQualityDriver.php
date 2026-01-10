<?php

declare(strict_types=1);

namespace App\Services\Favicon\Drivers;

use AshAllenDesign\FaviconFetcher\Collections\FaviconCollection;
use AshAllenDesign\FaviconFetcher\Concerns\HasDefaultFunctionality;
use AshAllenDesign\FaviconFetcher\Concerns\MakesHttpRequests;
use AshAllenDesign\FaviconFetcher\Concerns\ValidatesUrls;
use AshAllenDesign\FaviconFetcher\Contracts\Fetcher;
use AshAllenDesign\FaviconFetcher\Exceptions\InvalidUrlException;
use AshAllenDesign\FaviconFetcher\Favicon;
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

        if ($this->useCache && $favicon = $this->attemptToFetchFromCache($url)) {
            return $favicon;
        }

        $favicon = $this->tryAppleTouchIcon($url);
        if ($favicon instanceof \AshAllenDesign\FaviconFetcher\Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->tryHighResFavicon($url);
        if ($favicon instanceof \AshAllenDesign\FaviconFetcher\Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->tryGoogleHighRes($url);
        if ($favicon instanceof \AshAllenDesign\FaviconFetcher\Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        return $this->tryDuckDuckGo($url);
    }

    public function fetchAll(string $url): FaviconCollection
    {
        throw new \Exception('fetchAll not supported by HighQualityDriver');
    }

    private function tryAppleTouchIcon(string $url): ?Favicon
    {
        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->httpClient()->get($url)
            );

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            if (preg_match('/<link[^>]+rel=["\']apple-touch-icon["\'][^>]+href=["\'](.*?)["\']/i', (string) $html, $matches)) {
                $iconUrl = $this->convertToAbsoluteUrl($url, $matches[1]);

                return new Favicon(
                    url: $url,
                    faviconUrl: $iconUrl,
                    fromDriver: $this,
                )->setIconType(Favicon::TYPE_APPLE_TOUCH_ICON)
                    ->setIconSize(180);
            }

            $iconUrl = $this->stripPathFromUrl($url).'/apple-touch-icon.png';
            /** @var Response $testResponse */
            $testResponse = $this->httpClient()->head($iconUrl);

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
                fn () => $this->httpClient()->get($url)
            );

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            $patterns = [
                '/sizes=["\']512x512["\'][^>]+href=["\'](.*?)["\']/i',
                '/href=["\'](.*?)["\']\s+[^>]*sizes=["\']512x512["\']/i',
                '/sizes=["\']256x256["\'][^>]+href=["\'](.*?)["\']/i',
                '/href=["\'](.*?)["\']\s+[^>]*sizes=["\']256x256["\']/i',
                '/sizes=["\']192x192["\'][^>]+href=["\'](.*?)["\']/i',
                '/href=["\'](.*?)["\']\s+[^>]*sizes=["\']192x192["\']/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, (string) $html, $matches)) {
                    $iconUrl = $this->convertToAbsoluteUrl($url, $matches[1]);

                    preg_match('/(\d+)x\d+/', $pattern, $sizeMatch);
                    $size = isset($sizeMatch[1]) ? (int) $sizeMatch[1] : null;

                    $favicon = new Favicon(
                        url: $url,
                        faviconUrl: $iconUrl,
                        fromDriver: $this,
                    );

                    if ($size !== null) {
                        $favicon->setIconSize($size);
                    }

                    return $favicon;
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

            $response = $this->withRequestExceptionHandling(
                fn () => $this->httpClient()->get($faviconUrl)
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

        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->httpClient()->get($faviconUrl)
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
        try {
            /** @var Response $response */
            $response = $this->httpClient()->head($favicon->getFaviconUrl());

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
