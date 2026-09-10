<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CancelIdentityConfirmation;
use App\Actions\Auth\CompleteIdentityMfa;
use App\Filament\Pages\Security;
use App\Http\Controllers\Auth\Concerns\ResolvesConfirmingUser;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class IdentityConfirmationMfaController
{
    use ResolvesConfirmingUser;

    public function __construct(private CompleteIdentityMfa $completeIdentityMfa) {}

    public function show(Request $request): View
    {
        $user = $this->user($request);

        return view('auth.confirm-identity-mfa', [
            'account' => $user->email,
            'expired' => ! IdentityConfirmation::mfaPendingFor($user),
        ]);
    }

    public function destroy(Request $request, CancelIdentityConfirmation $cancelIdentityConfirmation): Response
    {
        $user = $this->user($request);
        $cancelIdentityConfirmation->execute($user);

        return $user->currentTeam === null
            ? to_route('dashboard')
            : redirect()->to(Security::getUrl(['tenant' => $user->currentTeam], panel: 'app'));
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);

        try {
            $this->completeIdentityMfa->execute(
                $user,
                $this->nullableString($request, 'code'),
                $this->nullableString($request, 'recovery_code'),
            );
        } catch (ValidationException $exception) {
            throw_if($request->wantsJson(), $exception);

            return back()->withErrors($exception->errors());
        }

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return $this->destination();
    }
}
