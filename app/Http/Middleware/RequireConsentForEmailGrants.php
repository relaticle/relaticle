<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\EmailGrant;
use Closure;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireConsentForEmailGrants
{
    // Passport skips consent when an active token for the client already holds the requested
    // scopes. Only the approval strips and adds email scopes, so these requests must reach it.
    public function handle(Request $request, Closure $next): Response
    {
        $scope = $request->query('scope');
        $requested = is_string($scope) ? explode(' ', $scope) : [];

        $namesEmailScope = array_any($requested, fn (string $scope): bool => EmailGrant::tryFrom($scope) instanceof EmailGrant);
        $wouldGainEmail = in_array(Registrar::OAUTH_SCOPE, $requested, true) && EmailGrant::offered() !== [];

        if ($namesEmailScope || $wouldGainEmail) {
            $request->merge(['prompt' => $this->withConsent($request->query('prompt'))]);
        }

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
