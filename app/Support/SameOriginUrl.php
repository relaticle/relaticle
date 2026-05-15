<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Uri;

final readonly class SameOriginUrl
{
    public static function rewrite(string $absoluteUrl): string
    {
        if (! app()->bound('request')) {
            return $absoluteUrl;
        }

        $request = resolve('request');
        $requestHost = $request->getHost();

        if ($requestHost === '' || $requestHost === 'localhost') {
            return $absoluteUrl;
        }

        $sourceUri = Uri::of($absoluteUrl);
        $sourcePath = $sourceUri->path();

        if (blank($sourcePath)) {
            return $absoluteUrl;
        }

        $sourceHost = $sourceUri->host();

        if (filled($sourceHost) && $sourceHost !== Uri::of((string) config('app.url'))->host()) {
            return $absoluteUrl;
        }

        $rewritten = $request->getSchemeAndHttpHost().'/'.ltrim($sourcePath, '/');
        $query = (string) $sourceUri->query();

        return $query === '' ? $rewritten : $rewritten.'?'.$query;
    }
}
