<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\PasskeyLoginResponse;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
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

it('falls back to the dashboard for a workspace the user was removed from', function (): void {
    $user = User::factory()->withTeam()->create();
    $revokedTeam = Team::factory()->create();
    $revokedTeam->users()->attach($user, ['role' => 'editor']);
    $revokedTeam->removeUser($user);

    $target = loginResponseFor($user, "/app/{$revokedTeam->slug}/companies");

    expect($user->fresh()->belongsToTeam($revokedTeam))->toBeFalse()
        ->and($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('falls back to the panel root for an external destination when the user has no workspace', function (): void {
    $user = User::factory()->create();

    $target = loginResponseFor($user, 'https://untrusted.example/collect');

    expect($target)->toBe(Filament::getPanel('app')->getUrl());
});

it('honors an absolute destination on the exact configured application host', function (): void {
    $user = User::factory()->withTeam()->create();
    $appUrl = (string) config('app.url');
    $scheme = parse_url($appUrl, PHP_URL_SCHEME);
    $host = parse_url($appUrl, PHP_URL_HOST);
    $intended = "{$scheme}://{$host}/oauth/authorize?client_id=019fec43";

    $target = loginResponseFor($user, $intended);

    expect($target)->toBe($intended);
});

it('falls back to the dashboard for an arbitrary subdomain of the configured host', function (): void {
    $user = User::factory()->withTeam()->create();

    $target = loginResponseFor($user, 'https://evil.'.parse_url((string) config('app.url'), PHP_URL_HOST).'/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

it('honors a preserved invitation destination reached before signing in', function (): void {
    $user = User::factory()->create();
    $invitation = TeamInvitation::factory()->create(['email' => $user->email]);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $target = loginResponseFor($user, route('team-invitations.token.accept', ['token' => $rawToken]));

    expect($target)->toBe(route('team-invitations.token.accept', ['token' => $rawToken]));
});

function passkeyRedirectFor(User $user, ?string $intended): string
{
    if ($intended !== null) {
        session(['url.intended' => $intended]);
    }

    $request = Request::create('/passkeys/login', 'POST');
    $request->setUserResolver(fn (): User => $user);

    $response = app(PasskeyLoginResponse::class)->toResponse($request);

    expect($response)->toBeInstanceOf(JsonResponse::class);
    assert($response instanceof JsonResponse);

    return (string) $response->getData(true)['redirect'];
}

it('sends a passkey sign-in to the url it was interrupted on', function (): void {
    $user = User::factory()->create();

    expect(passkeyRedirectFor($user, '/app/scheduled-deletion'))->toEndWith('/app/scheduled-deletion');
});

it('falls back to the panel root when a passkey sign-in has no intended url', function (): void {
    $user = User::factory()->create();

    expect(passkeyRedirectFor($user, null))->toBe(Filament::getPanel('app')->getUrl());
});
