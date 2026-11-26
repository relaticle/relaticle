<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final readonly class IndexNow
{
    public const string HOST = 'relaticle.com';

    private const string ENDPOINT = 'https://api.indexnow.org/indexnow';

    private const int URLS_PER_REQUEST = 10_000;

    public static function key(): ?string
    {
        $key = config('services.indexnow.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @param  list<string>  $urls */
    public function submit(array $urls): void
    {
        $key = self::key();

        if ($key === null) {
            return;
        }

        $ownUrls = array_values(array_filter(
            $urls,
            fn (string $url): bool => parse_url($url, PHP_URL_HOST) === self::HOST,
        ));

        foreach (array_chunk($ownUrls, self::URLS_PER_REQUEST) as $batch) {
            try {
                Http::timeout(15)->post(self::ENDPOINT, [
                    'host' => self::HOST,
                    'key' => $key,
                    'keyLocation' => 'https://'.self::HOST.'/'.$key.'.txt',
                    'urlList' => $batch,
                ])->throw();
            } catch (ConnectionException|RequestException $exception) {
                report($exception);
            }
        }
    }
}
