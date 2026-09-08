<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\LoginDestination;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final readonly class CompleteAuthentication
{
    private const array PRESERVED_SESSION_KEYS = [
        'fathom.track_signup',
        'fathom.track_workspace_created',
        'mcp.oauth.team_id',
        'auth.link_suggestion',
    ];

    public function __construct(private LoginDestination $loginDestination) {}

    public function execute(): string
    {
        $pending = AuthenticationSession::pending();
        $user = $pending === [] ? null : User::query()->find($pending['user_id']);

        if (! $user instanceof User) {
            AuthenticationSession::clear();

            throw $this->expired();
        }

        $pending = AuthenticationSession::consume($user);

        if ($pending === []) {
            AuthenticationSession::clear();

            throw $this->expired();
        }

        $session = resolve(Session::class);
        $intended = $session->get('url.intended');
        $preserved = $this->capturePreservedSessionValues($session);

        $session->invalidate();
        $session->put($preserved);

        Auth::guard('web')->login($user, $pending['remember']);
        $session->regenerateToken();

        AuthenticationSession::markComplete($user);

        return $this->loginDestination->resolve($user, is_string($intended) ? $intended : null);
    }

    // These are dotted session paths (`fathom.track_signup` nests under `fathom`),
    // so each is read and later restored through the session's dot-aware accessors.
    /**
     * @return array<string, mixed>
     */
    private function capturePreservedSessionValues(Session $session): array
    {
        $preserved = [];

        foreach (self::PRESERVED_SESSION_KEYS as $key) {
            if ($session->has($key)) {
                $preserved[$key] = $session->get($key);
            }
        }

        return $preserved;
    }

    private function expired(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => [__('auth.mfa.expired')],
        ]);
    }
}
