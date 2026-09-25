<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticatePasskey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;

final readonly class PasskeySessionController
{
    public function __construct(private AuthenticatePasskey $authenticatePasskey) {}

    public function index(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();

        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    public function store(PasskeyVerificationRequest $request): JsonResponse
    {
        $next = $this->authenticatePasskey->execute(
            $request->credential(),
            $request->verificationOptions(),
            $request->remember(),
        );

        return response()->json(['redirect' => $next]);
    }
}
