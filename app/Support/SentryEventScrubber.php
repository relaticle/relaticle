<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route as Router;
use Sentry\Event;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

final readonly class SentryEventScrubber
{
    private const array SECRET_PARAMETERS = ['token', 'email', 'hash'];

    private const string FILTERED = '[Filtered]';

    private const string TRACE_REFERER = 'http.request.header.referer';

    public static function scrub(Event $event): Event
    {
        $route = request()->route();
        $route = $route instanceof Route ? $route : null;

        $request = $event->getRequest();

        if ($route instanceof Route && is_string($request['url'] ?? null)) {
            $request['url'] = self::mask($request['url'], $route);
        }

        foreach ($request['headers'] ?? [] as $name => $values) {
            if (strtolower((string) $name) === 'referer') {
                $request['headers'][$name] = array_map(self::maskReferer(...), (array) $values);
            }
        }

        $event->setRequest($request);

        $trace = $event->getContexts()['trace'] ?? null;

        if (is_array($trace) && is_array($trace['data'] ?? null)) {
            if ($route instanceof Route && is_string($trace['data']['url'] ?? null)) {
                $trace['data']['url'] = self::mask($trace['data']['url'], $route);
            }

            if (is_string($trace['data'][self::TRACE_REFERER] ?? null)) {
                $trace['data'][self::TRACE_REFERER] = self::maskReferer($trace['data'][self::TRACE_REFERER]);
            }

            $event->setContext('trace', $trace);
        }

        return $event;
    }

    private static function maskReferer(string $url): string
    {
        try {
            $request = Request::create($url);
        } catch (BadRequestException) {
            return self::FILTERED;
        }

        $route = Arr::first(
            Router::getRoutes()->get('GET'),
            fn (Route $candidate): bool => $candidate->matches($request),
        );

        return $route instanceof Route ? self::mask($url, $route) : $url;
    }

    private static function mask(string $url, Route $route): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = explode('/', $path);
        $routeSegments = explode('/', '/'.$route->uri());

        if (count($segments) !== count($routeSegments)) {
            return $url;
        }

        foreach ($routeSegments as $index => $routeSegment) {
            $parameter = trim($routeSegment, '{}?');

            if ($routeSegment !== $parameter && in_array($parameter, self::SECRET_PARAMETERS, true)) {
                $segments[$index] = '{'.$parameter.'}';
            }
        }

        return str_replace($path, implode('/', $segments), $url);
    }
}
