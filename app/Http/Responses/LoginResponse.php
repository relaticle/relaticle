<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
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

        if (! $user || ! $user->currentTeam) {
            return redirect()->intended(Filament::getUrl());
        }

        $dashboard = Dashboard::getUrl(['tenant' => $user->currentTeam]);

        // Honour a pre-login deep link only when it points at a workspace the
        // user can actually access. A stale or cross-tenant `url.intended`
        // (e.g. a bookmark for a workspace they were removed from) would
        // otherwise bounce them straight into a 403/404 after signing in.
        $intended = session()->pull('url.intended');

        if (is_string($intended) && $intended !== '' && $this->intendedIsAccessible($intended, $user)) {
            return redirect()->to($intended);
        }

        return redirect()->to($dashboard);
    }

    private function intendedIsAccessible(string $intended, User $user): bool
    {
        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        // Drop the panel path prefix for path-based panels (/app/{slug}/...).
        // Domain-based panels ({domain}/{slug}/...) have no such prefix.
        if (($segments[0] ?? null) === config('app.app_panel_path', 'app')) {
            array_shift($segments);
        }

        $slug = $segments[0] ?? null;

        if ($slug === null) {
            return false;
        }

        $team = Team::query()->where('slug', $slug)->first();

        return $team !== null && $user->belongsToTeam($team);
    }
}
