<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Http\Responses\LoginResponse;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function loginResponseFor(User $user, ?string $intended): string
{
    if ($intended !== null) {
        session(['url.intended' => $intended]);
    }

    $request = Request::create('/app/login', 'POST');
    $request->setUserResolver(fn (): User => $user);

    $response = app(LoginResponse::class)->toResponse($request);

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    assert($response instanceof RedirectResponse);

    return $response->getTargetUrl();
}

it('honors a pre-login deep link into a workspace the user belongs to', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $target = loginResponseFor($user, "/app/{$team->slug}/companies");

    expect($target)->toEndWith("/app/{$team->slug}/companies");
});

it('falls back to the dashboard for a cross-tenant intended url', function (): void {
    $user = User::factory()->withTeam()->create();
    $otherTeam = Team::factory()->create();

    $target = loginResponseFor($user, "/app/{$otherTeam->slug}/chats/01HZ");

    expect($user->belongsToTeam($otherTeam))->toBeFalse()
        ->and($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('falls back to the dashboard when there is no intended url', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, null);

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('falls back to the dashboard when the intended url has no resolvable workspace slug', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, '/app');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});
