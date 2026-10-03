<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

final readonly class RequestAbility
{
    public static function for(Request $request): string
    {
        if ($request->route()?->getActionMethod() === 'index') {
            return 'read';
        }

        return match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };
    }

    public static function isRead(Request $request): bool
    {
        return self::for($request) === 'read';
    }
}
