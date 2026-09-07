<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Auth\LoginDestination;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

final readonly class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse(mixed $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $destination = resolve(LoginDestination::class)->resolve($user, session()->pull('url.intended'));

        return new JsonResponse(['redirect' => $destination]);
    }
}
