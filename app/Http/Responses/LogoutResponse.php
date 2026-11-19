<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Auth\Http\Responses\LogoutResponse as VendorLogoutResponse;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

final readonly class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector // @pest-ignore-type
    {
        $response = (new VendorLogoutResponse)->toResponse($request);

        if ($response instanceof RedirectResponse) {
            $response->headers->set('Clear-Site-Data', '"storage"');
        }

        return $response;
    }
}
