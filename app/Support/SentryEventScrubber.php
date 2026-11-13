<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;

final readonly class SentryEventScrubber
{
    private const array TOKEN_PATHS = [
        '#(?<![^/])(invitations|join)/[^/?\#]+#' => '$1/{token}',
        '#(email-change-verification/verify/[^/]+)/[^/?\#]+#' => '$1/{email}',
        '#((?:email|email-verification)/verify/[^/]+)/[^/?\#]+#' => '$1/{hash}',
    ];

    public static function scrub(Event $event): Event
    {
        $request = $event->getRequest();

        unset($request['data'], $request['query_string'], $request['cookies']);

        if (isset($request['url']) && is_string($request['url'])) {
            $request['url'] = self::scrubUrl($request['url']);
        }

        foreach ($request['headers'] ?? [] as $name => $value) {
            if (strtolower((string) $name) === 'referer') {
                $request['headers'][$name] = is_array($value) ? array_map(self::scrubUrl(...), $value) : self::scrubUrl((string) $value);
            }
        }

        $event->setRequest($request);

        if ($event->getTransaction() !== null) {
            $event->setTransaction(self::scrubPath($event->getTransaction()));
        }

        $trace = $event->getContexts()['trace'] ?? null;

        if (is_array($trace) && isset($trace['data']['url']) && is_string($trace['data']['url'])) {
            $trace['data']['url'] = self::scrubUrl($trace['data']['url']);
            $event->setContext('trace', $trace);
        }

        return $event;
    }

    private static function scrubUrl(string $url): string
    {
        return self::scrubPath((string) preg_replace('/[?#].*$/', '', $url));
    }

    private static function scrubPath(string $path): string
    {
        return (string) preg_replace(array_keys(self::TOKEN_PATHS), array_values(self::TOKEN_PATHS), $path);
    }
}
