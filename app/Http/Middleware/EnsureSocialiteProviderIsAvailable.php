<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SocialiteProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureSocialiteProviderIsAvailable
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $provider = $request->route('provider');

        if (! $provider instanceof SocialiteProvider) {
            $provider = SocialiteProvider::tryFrom(is_string($provider) ? $provider : '');
        }

        abort_unless($provider?->isAvailable() ?? false, 404);

        return $next($request);
    }
}
