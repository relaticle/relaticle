<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmIdentity;
use App\Enums\AuthMethod;
use App\Http\Controllers\Auth\Concerns\ResolvesConfirmingUser;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Fortify's ConfirmablePasswordController via container binding so a
 * direct POST to the confirm-password routes follows the same scoped-proof
 * policy as the UI (RequireIdentityConfirmation guards everything else).
 */
final readonly class IdentityConfirmationController
{
    use ResolvesConfirmingUser;

    public function __construct(private ConfirmIdentity $confirmIdentity) {}

    public function show(Request $request): View
    {
        $user = $this->user($request);

        return view('auth.confirm-identity', [
            'hasPassword' => $user->hasPassword(),
            'hasPasskey' => $user->hasPasskey(),
            'provider' => $user->socialAccounts()->first()?->provider_name,
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);

        try {
            $next = $this->confirmIdentity->execute(
                $user,
                $this->attemptId($user),
                AuthMethod::PASSWORD,
                [
                    'password' => (string) $request->string('password'),
                    'code' => $this->nullableString($request, 'code'),
                    'recovery_code' => $this->nullableString($request, 'recovery_code'),
                ],
            );
        } catch (ValidationException $exception) {
            throw_if($request->wantsJson(), $exception);

            return back()->withErrors($exception->errors());
        }

        if ($next !== '') {
            throw_if($request->wantsJson(), ValidationException::withMessages([
                'code' => [__('auth.confirm.mfa_required')],
            ]));

            return redirect()->to($next);
        }

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return $this->destination();
    }

    private function attemptId(User $user): ?string
    {
        $pending = AuthenticationSession::pendingOperation();

        return $pending !== [] && $pending['user_id'] === (string) $user->getAuthIdentifier() ? $pending['id'] : null;
    }
}
