<?php

declare(strict_types=1);

namespace App\Services\Favicon\Drivers;

use App\Models\Company;
use App\Support\Http\HostResolver;
use App\Support\Http\SsrfGuard;
use AshAllenDesign\FaviconFetcher\Collections\FaviconCollection;
use AshAllenDesign\FaviconFetcher\Concerns\HasDefaultFunctionality;
use AshAllenDesign\FaviconFetcher\Concerns\MakesHttpRequests;
use AshAllenDesign\FaviconFetcher\Concerns\ValidatesUrls;
use AshAllenDesign\FaviconFetcher\Contracts\Fetcher;
use AshAllenDesign\FaviconFetcher\Exceptions\ConnectionException;
use AshAllenDesign\FaviconFetcher\Exceptions\InvalidUrlException;
use AshAllenDesign\FaviconFetcher\Favicon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class HighQualityDriver implements Fetcher
{
    use HasDefaultFunctionality;
    use MakesHttpRequests;
    use ValidatesUrls;

    private const int BUDGET_SECONDS = 20;

    private const int REQUEST_TIMEOUT_SECONDS = 5;

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int MINIMUM_SECONDS_PER_REQUEST = 1;

    private const int MINIMUM_SECONDS_TO_VERIFY = 2;

    private CarbonImmutable $deadline;

    private bool $skippedForLackOfTime = false;

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
        $bounded = clone $this;
        $bounded->deadline = now()->addSeconds(self::BUDGET_SECONDS);

        return $bounded->search($url);
    }

    private function search(string $url): ?Favicon
    {
        throw_unless($this->urlIsValid($url), InvalidUrlException::class, $url.' is not a valid URL');

        throw_if($this->hostDoesNotResolve($url), ConnectionException::class, "Could not resolve host for {$url}");

        if (! SsrfGuard::isAllowed($url)) {
            return null;
        }

        if ($this->useCache && $favicon = $this->attemptToFetchFromCache($url)) {
            return $favicon;
        }

        $siteUnreachable = null;

        try {
            $favicon = $this->fromSite($url);
        } catch (ConnectionException $exception) {
            $favicon = null;
            $siteUnreachable = $exception;
        }

        if ($favicon instanceof Favicon) {
            return $favicon;
        }

        $favicon = $this->whileTimeLeft(fn (): ?Favicon => $this->tryGoogleHighRes($url));
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->whileTimeLeft(fn (): ?Favicon => $this->tryDuckDuckGo($url));

        if (! $favicon instanceof Favicon) {
            throw_if($siteUnreachable instanceof ConnectionException, $siteUnreachable);
            throw_if($this->skippedForLackOfTime, ConnectionException::class, "Ran out of time looking for a favicon for {$url}");
        }

        return $favicon;
    }

    private function secondsLeft(): float
    {
        return now()->diffInSeconds($this->deadline, false);
    }

    private function whileTimeLeft(Closure $source): ?Favicon
    {
        if ($this->hasTimeLeft()) {
            return $source();
        }

        $this->skippedForLackOfTime = true;

        return null;
    }

    private function noteIfOutOfTime(): void
    {
        if (! $this->hasTimeLeft()) {
            $this->skippedForLackOfTime = true;
        }
    }

    private function hasTimeLeft(): bool
    {
        return $this->secondsLeft() >= self::MINIMUM_SECONDS_PER_REQUEST;
    }

    private function hostDoesNotResolve(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        return $host !== '' && resolve(HostResolver::class)->addresses($host) === [];
    }

    private function fromSite(string $url): ?Favicon
    {
        $favicon = $this->tryAppleTouchIcon($url);
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        $favicon = $this->tryHighResFavicon($url);
        if ($favicon instanceof Favicon && $this->faviconIsAccessible($favicon)) {
            return $favicon;
        }

        return null;
    }

    public function fetchAll(string $url): FaviconCollection
    {
        throw new \Exception('fetchAll not supported by HighQualityDriver');
    }

    private function guardedHttpClient(): PendingRequest
    {
        if (! $this->hasTimeLeft()) {
            $this->skippedForLackOfTime = true;

            throw new ConnectionException('Ran out of time looking for a favicon');
        }

        return SsrfGuard::guard(
            $this->httpClient()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS),
            $this->deadline,
        );
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
            $testResponse = $this->guardedHttpClient()->get($iconUrl);

            if ($this->servesStorableIcon($testResponse)) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $iconUrl,
                    fromDriver: $this,
                )->setIconType(Favicon::TYPE_APPLE_TOUCH_ICON)
                    ->setIconSize(180);
            }

        } catch (ConnectionException $exception) {
            throw $exception;
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

        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (\Exception) {
            // Fall through
        }

        return null;
    }

    private function tryGoogleHighRes(string $url): ?Favicon
    {
        $host = $this->bareHost($url);

        if ($host === null) {
            return null;
        }

        try {
            $faviconUrl = 'https://www.google.com/s2/favicons?sz=256&domain='.rawurlencode($host);

            if (! SsrfGuard::isAllowed($faviconUrl)) {
                return null;
            }

            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($faviconUrl)
            );

            if ($this->servesStorableIcon($response)) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $faviconUrl,
                    fromDriver: $this,
                )->setIconSize(256);
            }

        } catch (\Exception) {
            $this->noteIfOutOfTime();
        }

        return null;
    }

    private function tryDuckDuckGo(string $url): ?Favicon
    {
        $host = $this->bareHost($url);

        if ($host === null) {
            return $this->notFound($url);
        }

        $faviconUrl = 'https://icons.duckduckgo.com/ip3/'.$host.'.ico';

        if (! SsrfGuard::isAllowed($faviconUrl)) {
            return null;
        }

        try {
            $response = $this->withRequestExceptionHandling(
                fn () => $this->guardedHttpClient()->get($faviconUrl)
            );

            if ($this->servesStorableIcon($response)) {
                return new Favicon(
                    url: $url,
                    faviconUrl: $faviconUrl,
                    fromDriver: $this,
                );
            }

        } catch (\Exception) {
            $this->noteIfOutOfTime();
        }

        return $this->notFound($url);
    }

    private function bareHost(string $url): ?string
    {
        $host = strtolower(preg_replace('/\\.$/', '', (string) parse_url($url, PHP_URL_HOST)));

        $isHostname = preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host) === 1;

        return $isHostname ? $host : null;
    }

    private function faviconIsAccessible(Favicon $favicon): bool
    {
        $faviconUrl = $favicon->getFaviconUrl();

        if (! SsrfGuard::isAllowed($faviconUrl)) {
            return false;
        }

        if ($this->secondsLeft() < self::MINIMUM_SECONDS_TO_VERIFY) {
            return true;
        }

        try {
            /** @var Response $response */
            $response = $this->guardedHttpClient()->get($faviconUrl);

            return $this->servesStorableIcon($response);
        } catch (\Exception) {
            return false;
        }
    }

    // Many sites answer a missing path with 200 and their own HTML page, and a Content-Type
    // header is only the server's claim about the bytes.
    private function servesStorableIcon(Response $response): bool
    {
        return $response->successful() && Company::logoExtensionFor($response->body()) !== null;
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
