<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmIdentity;
use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

final readonly class PasskeyConfirmationController
{
    public function __construct(private ConfirmIdentity $confirmIdentity) {}

    public function index(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate($this->user());

        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(PasskeyVerificationRequest $request): JsonResponse
    {
        $user = $this->user();
        $pending = AuthenticationSession::pendingOperation();
        $attemptId = $pending !== [] && $pending['user_id'] === (string) $user->getAuthIdentifier() ? $pending['id'] : null;

        $next = $this->confirmIdentity->execute($user, $attemptId, AuthMethod::PASSKEY, [
            'passkey_credential' => $request->credential(),
            'passkey_options' => $request->verificationOptions(),
        ]);

        return response()->json([
            'confirmed' => $next === '',
            'redirect' => $next !== '' ? $next : redirect()->intended(Fortify::redirects('password-confirmation'))->getTargetUrl(),
        ]);
    }

    private function user(): User
    {
        $user = Auth::guard('web')->user();
        assert($user instanceof User);

        return $user;
    }
}
