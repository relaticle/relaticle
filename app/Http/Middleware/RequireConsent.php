<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireConsent
{
    // Passport skips consent when an active token already holds the requested scopes. Only the
    // approve step derives scopes from the user's role, so every request must reach it.
    public function handle(Request $request, Closure $next): Response
    {
        $request->merge(['prompt' => $this->withConsent($request->query('prompt'))]);

        return $next($request);
    }

    // `none` makes Passport ignore every other value and approve without the screen.
    private function withConsent(mixed $prompt): string
    {
        return collect(is_string($prompt) ? explode(' ', $prompt) : [])
            ->reject(fn (string $value): bool => in_array($value, ['', 'none', 'consent'], true))
            ->push('consent')
            ->implode(' ');
    }
}
