<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\LoginDestination;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final readonly class CompleteAuthentication
{
    private const array PRESERVED_SESSION_KEYS = [
        'fathom.track_signup',
        'fathom.track_workspace_created',
        'mcp.oauth.team_id',
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
        $preserved = Arr::only($session->all(), self::PRESERVED_SESSION_KEYS);

        $session->invalidate();
        $session->put($preserved);

        Auth::guard('web')->login($user, $pending['remember']);
        $session->regenerateToken();

        return $this->loginDestination->resolve($user, is_string($intended) ? $intended : null);
    }

    private function expired(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => [__('auth.mfa.expired')],
        ]);
    }
}
