<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CheckScheduledDeletion
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user?->isScheduledForDeletion() && ! $request->routeIs('*.scheduled-deletion', 'scheduled-deletion', 'logout', '*.auth.logout')) {
            return to_route('filament.app.scheduled-deletion');
        }

        return $next($request);
    }
}
