<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Support\Str;

final class CalendarPushWebhookUrl
{
    public static function forProvider(string $provider): string
    {
        return route('calendar-push.webhook', ['provider' => $provider]);
    }

    public static function isPubliclyReachable(): bool
    {
        $url = config('app.url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        if (! Str::startsWith($url, 'https://')) {
            return false;
        }

        return ! Str::contains($url, ['localhost', '.test', '127.0.0.1']);
    }
}
