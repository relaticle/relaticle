<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Support\Auth\LoginDestination;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

final readonly class LoginResponse implements \Filament\Auth\Http\Responses\Contracts\LoginResponse
{
    /** @phpstan-ignore-next-line return.unusedType */
    public function toResponse($request): RedirectResponse|Redirector // @pest-ignore-type
    {
        $panel = Filament::getCurrentPanel();

        if ($panel?->getId() === 'sysadmin') {
            return redirect()->intended($panel->getUrl());
        }

        $user = $request->user('web');

        if (! $user instanceof User) {
            return redirect()->intended(Filament::getUrl());
        }

        $destination = resolve(LoginDestination::class)->resolve($user, session()->pull('url.intended'));

        return redirect()->to($destination);
    }
}
