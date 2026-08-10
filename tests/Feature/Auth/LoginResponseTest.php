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

it('honors a pre-login deep link to a non-tenant destination such as the oauth consent screen', function (): void {
    $user = User::factory()->withTeam()->create();
    $intended = '/oauth/authorize?client_id=019fec43&response_type=code&scope=mcp%3Ause';

    $target = loginResponseFor($user, $intended);

    expect($target)->toEndWith($intended);
});

it('falls back to the dashboard for an intended url on another host', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, 'https://evil.example.com/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('falls back to the dashboard for a protocol-relative intended url', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, '//evil.example.com/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('falls back to the dashboard for a panel url whose slug is not a workspace', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, '/app/not-a-workspace/companies');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});
