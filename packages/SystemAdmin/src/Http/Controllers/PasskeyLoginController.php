<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Relaticle\SystemAdmin\Actions\Passkeys\GenerateVerificationOptions;
use Relaticle\SystemAdmin\Actions\Passkeys\VerifyPasskey;
use Relaticle\SystemAdmin\Http\Requests\PasskeyVerificationRequest;
use RuntimeException;

final class PasskeyLoginController extends Controller
{
    public function index(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate->execute();

        $request->session()->put(PasskeyVerificationRequest::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * @throws InvalidPasskeyException
     */
    public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $passkey = $verify->execute($request->credential(), $request->verificationOptions());

        $guard = Auth::guard('sysadmin');

        throw_unless($guard instanceof StatefulGuard, RuntimeException::class, 'Passkeys require a stateful authentication guard.');

        $panel = Filament::getPanel('sysadmin');

        if (! $passkey->user->canAccessPanel($panel)) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        $intended = $request->session()->pull('url.intended', $panel->getUrl());

        $guard->login($passkey->user, $request->remember());

        $request->session()->regenerate();

        return response()->json(['redirect' => $intended]);
    }
}
