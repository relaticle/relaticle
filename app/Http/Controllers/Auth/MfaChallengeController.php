<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CancelAuthentication;
use App\Actions\Auth\VerifyMfa;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Symfony\Component\HttpFoundation\Response;

final readonly class MfaChallengeController
{
    public function __construct(private VerifyMfa $verifyMfa) {}

    public function create(Request $request): View|RedirectResponse
    {
        $pending = AuthenticationSession::pending();
        $user = $pending === [] ? null : User::query()->find($pending['user_id']);

        if (! $request->hasSession() || ! $user instanceof User || ! AuthenticationSession::isValidFor($user) || ! $user->hasEnabledTwoFactorAuthentication()) {
            AuthenticationSession::clear();

            if ($pending !== [] || session('errors')?->hasAny(['code', 'recovery_code'])) {
                return view('auth.mfa-challenge', ['account' => null, 'expired' => true]);
            }

            return to_route('login');
        }

        return view('auth.mfa-challenge', ['account' => $user->email, 'expired' => false]);
    }

    public function destroy(CancelAuthentication $cancelAuthentication): RedirectResponse
    {
        $cancelAuthentication->execute();

        return to_route('login');
    }

    public function store(TwoFactorLoginRequest $request): Response
    {
        $validated = $request->validated();
        try {
            $next = $this->verifyMfa->execute(
                is_string($validated['code'] ?? null) ? $validated['code'] : null,
                is_string($validated['recovery_code'] ?? null) ? $validated['recovery_code'] : null,
            );
        } catch (ValidationException $exception) {
            throw_if($request->wantsJson(), $exception);

            return to_route('two-factor.login')->withErrors($exception->errors());
        }

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return redirect()->to($next);
    }
}
