<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteIdentityMfa;
use App\Http\Controllers\Auth\Concerns\ResolvesConfirmingUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MFA follow-up to IdentityConfirmationController, reached only when a
 * passkey ceremony proved its primary factor but enrolled MFA is still owed
 * (the browser ceremony has no field to collect a code inline).
 */
final readonly class IdentityConfirmationMfaController
{
    use ResolvesConfirmingUser;

    public function __construct(private CompleteIdentityMfa $completeIdentityMfa) {}

    public function show(): View
    {
        return view('auth.confirm-identity-mfa');
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
