<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticatePassword;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginViewResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasswordSessionController
{
    public function __construct(private AuthenticatePassword $authenticatePassword) {}

    public function create(Request $request): LoginViewResponse
    {
        if ($request->hasSession()) {
            AuthenticationSession::clear();
        }

        return resolve(LoginViewResponse::class);
    }

    public function store(LoginRequest $request): Response
    {
        $next = $this->authenticatePassword->execute(
            (string) $request->input(Fortify::username()),
            (string) $request->input('password'),
            $request->boolean('remember'),
        );

        if ($request->wantsJson()) {
            return response()->json(['two_factor' => ! Auth::guard('web')->check()]);
        }

        return redirect()->to($next);
    }

    public function destroy(Request $request): LogoutResponse
    {
        AuthenticationSession::clear();
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return resolve(LogoutResponse::class);
    }
}
