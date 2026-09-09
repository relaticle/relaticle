<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkey;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires and spends a proven, matching operation grant before the wrapped
 * route runs. RequireIdentityConfirmation only checks the generic freshness
 * window; a vendor write route (passkey.store, passkey.destroy, the raw
 * Fortify two-factor routes) needs its own grant to be authorized, not just
 * "someone confirmed something recently".
 */
final readonly class RequireOperationGrant
{
    public function handle(Request $request, Closure $next, string $operation, ?string $target = null): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        try {
            AuthenticationSession::consumeOperation($user, $operation, $this->targetId($request, $operation, $target));
        } catch (ValidationException $exception) {
            throw_if($request->wantsJson(), $exception);

            return redirect()->to(route('password.confirm'))->withErrors($exception->errors());
        }

        return $next($request);
    }

    private function targetId(Request $request, string $operation, ?string $target): ?string
    {
        if ($operation !== 'delete_passkey') {
            return $target;
        }

        $passkey = $request->route('passkey');

        return match (true) {
            $passkey instanceof Passkey => (string) $passkey->getKey(),
            is_numeric($passkey) => (string) $passkey,
            default => null,
        };
    }
}
