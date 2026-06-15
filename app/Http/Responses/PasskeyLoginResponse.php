<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

final readonly class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'redirect' => redirect()->intended(Filament::getPanel('app')->getUrl())->getTargetUrl(),
        ]);
    }
}
