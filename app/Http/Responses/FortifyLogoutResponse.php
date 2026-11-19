<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Http\Responses\LogoutResponse as VendorLogoutResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class FortifyLogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): Response
    {
        $response = (new VendorLogoutResponse)->toResponse($request);
        $response->headers->set('Clear-Site-Data', '"storage"');

        return $response;
    }
}
