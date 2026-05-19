<?php

declare(strict_types=1);

use App\Http\Responses\PasskeyLoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

it('returns the Filament admin panel URL as JSON redirect', function (): void {
    $response = (new PasskeyLoginResponse)->toResponse(Request::create('/passkeys/login', 'POST'));

    expect($response)->toBeInstanceOf(JsonResponse::class);

    /** @var array{redirect: string} $payload */
    $payload = $response->getData(true);

    expect($payload)->toHaveKey('redirect')
        ->and($payload['redirect'])->toBe(Filament::getPanel('app')->getUrl());
});
