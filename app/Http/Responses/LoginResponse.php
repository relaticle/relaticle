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
        if (! $this->isSameSite($intended)) {
            return false;
        }

        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        $isPanelUrl = ($segments[0] ?? null) === config('app.app_panel_path', 'app');

        // Drop the panel path prefix for path-based panels (/app/{slug}/...).
        // Domain-based panels ({domain}/{slug}/...) have no such prefix.
        if ($isPanelUrl) {
            array_shift($segments);
        }

        $slug = $segments[0] ?? null;

        if ($slug === null) {
            return false;
        }

        $team = Team::query()->where('slug', $slug)->first();

        // Non-tenant destinations on our own host — the OAuth consent screen at
        // /oauth/authorize being the one that matters — carry no workspace to check,
        // so the tenant guard below cannot speak to them. Dropping them silently
        // stranded every first-run MCP connector: the user signed in and landed on the
        // dashboard while the authorization request was discarded.
        if (! $team instanceof Team) {
            return ! $isPanelUrl;
        }

        return $user->belongsToTeam($team);
    }

    private function isSameSite(string $intended): bool
    {
        $host = parse_url($intended, PHP_URL_HOST);

        if ($host === null || $host === false) {
            return str_starts_with($intended, '/') && ! str_starts_with($intended, '//');
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($appHost) || $appHost === '') {
            return false;
        }

        return $host === $appHost || str_ends_with($host, '.'.$appHost);
    }
}
