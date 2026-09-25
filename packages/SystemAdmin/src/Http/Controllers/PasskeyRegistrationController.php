<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Controllers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Relaticle\SystemAdmin\Actions\Passkeys\GenerateRegistrationOptions;
use Relaticle\SystemAdmin\Actions\Passkeys\StorePasskey;
use Relaticle\SystemAdmin\Http\Requests\PasskeyRegistrationRequest;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class PasskeyRegistrationController extends Controller
{
    /**
     * @throws AuthenticationException
     */
    public function index(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        // The profile page mints this grant only after verifying a one-time code, so
        // adding a credential costs the second factor and not just the session cookie.
        abort_unless(PasskeyRegistrationRequest::isGranted($request), 403);

        $options = $generate->execute($this->administrator());

        $request->session()->put(PasskeyRegistrationRequest::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    /**
     * @throws AuthenticationException|InvalidPasskeyException
     */
    public function store(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        $request->session()->forget(PasskeyRegistrationRequest::GRANT_KEY);

        $passkey = $store->execute(
            $this->administrator(),
            $request->string('name')->toString(),
            $request->credential(),
            $request->registrationOptions(),
        );

        return response()->json(['id' => $passkey->id], 201);
    }

    /**
     * @throws AuthenticationException
     */
    private function administrator(): SystemAdministrator
    {
        $administrator = Auth::guard('sysadmin')->user();

        throw_unless($administrator instanceof SystemAdministrator, AuthenticationException::class);

        return $administrator;
    }
}
