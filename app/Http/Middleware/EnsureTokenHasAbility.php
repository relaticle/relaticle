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
        $token = $request->user()?->currentAccessToken();

        // Passport's cookie guard hands out a TransientToken whose can() is always true,
        // so a browser session could otherwise bypass every scope.
        if ($this->isCookieSession($token)) {
            return response()->json(['message' => 'This credential cannot access the API.'], 403);
        }

        // First-party Sanctum sessions carry no token and are authorized by policies.
        if (! $this->carriesAbilities($token)) {
            return $next($request);
        }

        // An upsert may create or update, so its route names both and the token must hold
        // each; deciding after the match would make the 403 an existence oracle.
        foreach ($abilities ?: [$this->resolveAbility($request)] as $ability) {
            throw_unless($token->can($ability), MissingAbilityException::class, [$ability]);
        }

        return $next($request);
    }

    private function isCookieSession(?object $token): bool
    {
        return $token instanceof PassportTransientToken;
    }

    /** @phpstan-assert-if-true PassportAccessToken<mixed>|PersonalAccessToken $token */
    private function carriesAbilities(?object $token): bool
    {
        return $token instanceof PassportAccessToken
            || ($token instanceof PersonalAccessToken && $token->getKey());
    }

    private function resolveAbility(Request $request): string
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
}
