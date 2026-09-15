<?php

declare(strict_types=1);

namespace App\Http\Controllers\Impersonation;

use App\Support\Impersonation\Impersonator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final readonly class StopImpersonationController
{
    public function __construct(private Impersonator $impersonator) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $this->impersonator->stop($request);

        return redirect()->to(url()->getSysadminUrl('users'));
    }
}
