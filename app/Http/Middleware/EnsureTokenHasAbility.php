<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken as PassportAccessToken;
use Laravel\Passport\TransientToken as PassportTransientToken;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureTokenHasAbility
{
    /**
     * @throws MissingAbilityException
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token !== null && $this->isOAuthCookieCredential($token)) {
            return response()->json(['message' => 'This credential cannot access the API.'], 403);
        }

        if ($token === null || ! $this->carriesAbilities($token)) {
            return $next($request);
        }

        // A route that mutates in more than one way — the upsert endpoints create
        // OR update — declares every ability it may exercise, and must hold all of
        // them regardless of which branch this particular request takes. Deciding
        // after the match would turn the 403 into an existence oracle.
        $required = $abilities === [] ? [$this->resolveAbility($request->method())] : $abilities;

        // Sanctum abilities and Passport scopes share the four names, and both
        // failures raise MissingAbilityException so the 403 body is identical
        // whichever credential the caller presented.
        foreach ($required as $ability) {
            throw_unless($token->can($ability), MissingAbilityException::class, [$ability]);
        }

        return $next($request);
    }

    /**
     * Whether the credential is a Passport cookie session.
     *
     * Passport's cookie guard attaches a TransientToken whose can() is
     * unconditionally true, so scopes can never be enforced on it. A browser
     * cookie is not an accepted API credential, so it is refused outright rather
     * than let through as if it were a first-party session.
     */
    private function isOAuthCookieCredential(object $token): bool
    {
        return $token instanceof PassportTransientToken;
    }

    /**
     * Whether the credential is an API token whose grant limits what it may do.
     *
     * First-party SPA/web requests (via Sanctum session auth) don't use
     * PersonalAccessToken. These requests bypass ability checks intentionally --
     * authorization is handled by policies.
     */
    private function carriesAbilities(object $token): bool
    {
        // An OAuth token holds the abilities the user consented to as scopes.
        // AccessToken::can() is false for an empty scope list, so a token that
        // consented to nothing is refused rather than waved through.
        if ($token instanceof PassportAccessToken) {
            return true;
        }

        return $token instanceof PersonalAccessToken && (bool) $token->getKey();
    }

    private function resolveAbility(string $method): string
    {
        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'read',
        };
    }
}
