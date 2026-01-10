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

final class GoogleHighResDriver implements Fetcher
{
    use HasDefaultFunctionality;
    use MakesHttpRequests;
    use ValidatesUrls;

    private const string BASE_URL = 'https://www.google.com/s2/favicons';

    public function __construct(private readonly int $size = 256) {}

    public function fetch(string $url): ?Favicon
    {
        throw_unless($this->urlIsValid($url), InvalidUrlException::class, $url.' is not a valid URL');

        if ($this->useCache && $favicon = $this->attemptToFetchFromCache($url)) {
            return $favicon;
        }

        $faviconUrl = self::BASE_URL.'?sz='.$this->size.'&domain='.$url;

        $response = $this->withRequestExceptionHandling(
            fn () => $this->httpClient()->get($faviconUrl)
        );

        return $response->successful()
            ? new Favicon(url: $url, faviconUrl: $faviconUrl, fromDriver: $this)
                ->setIconSize($this->size)
            : $this->notFound($url);
    }

    public function fetchAll(string $url): FaviconCollection
    {
        throw new \Exception('Google API does not support fetching all favicons');
    }
}
