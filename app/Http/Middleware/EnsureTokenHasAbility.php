<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureTokenHasAbility
{
    /**
     * @throws MissingAbilityException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // First-party SPA/web requests (via Sanctum session auth) don't use PersonalAccessToken.
        // These requests bypass ability checks intentionally -- authorization is handled by policies.
        if (! $token instanceof PersonalAccessToken || ! $token->getKey()) {
            return $next($request);
        }

        $ability = $this->resolveAbility($request->method());

        throw_unless($token->can($ability), MissingAbilityException::class, [$ability]);

        return $next($request);
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
