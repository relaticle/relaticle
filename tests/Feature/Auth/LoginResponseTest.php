<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Http\Responses\LoginResponse;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $target = loginResponseFor($user, "/app/{$workspace->slug}/companies");

    expect($target)->toEndWith("/app/{$workspace->slug}/companies");
});

it('falls back to the dashboard for a cross-tenant intended url', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create();

    $target = loginResponseFor($user, "/app/{$otherWorkspace->slug}/chats/01HZ");

    expect($user->belongsToWorkspace($otherWorkspace))->toBeFalse()
        ->and($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the dashboard when there is no intended url', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, null);

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the dashboard when the intended url has no resolvable workspace slug', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, '/app');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('honors a pre-login deep link to a non-tenant destination such as the oauth consent screen', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $intended = '/oauth/authorize?client_id=019fec43&response_type=code&scope=mcp%3Ause';

    $target = loginResponseFor($user, $intended);

    expect($target)->toEndWith($intended);
});

it('falls back to the dashboard for an intended url on another host', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, 'https://evil.example.com/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the dashboard for a protocol-relative intended url', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, '//evil.example.com/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the dashboard for a panel url whose slug is not a workspace', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, '/app/not-a-workspace/companies');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the dashboard for a workspace the user was removed from', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $revokedWorkspace = Workspace::factory()->create();
    $revokedWorkspace->users()->attach($user, ['role' => 'editor']);
    $revokedWorkspace->removeUser($user);

    $target = loginResponseFor($user, "/app/{$revokedWorkspace->slug}/companies");

    expect($user->fresh()->belongsToWorkspace($revokedWorkspace))->toBeFalse()
        ->and($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('falls back to the panel root for an external destination when the user has no workspace', function (): void {
    $user = User::factory()->create();

    $target = loginResponseFor($user, 'https://untrusted.example/collect');

    expect($target)->toBe(Filament::getPanel('app')->getUrl());
});

it('honors an absolute destination on the exact configured application host', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $appUrl = (string) config('app.url');
    $scheme = parse_url($appUrl, PHP_URL_SCHEME);
    $host = parse_url($appUrl, PHP_URL_HOST);
    $intended = "{$scheme}://{$host}/oauth/authorize?client_id=019fec43";

    $target = loginResponseFor($user, $intended);

    expect($target)->toBe($intended);
});

it('falls back to the dashboard for an arbitrary subdomain of the configured host', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $target = loginResponseFor($user, 'https://evil.'.parse_url((string) config('app.url'), PHP_URL_HOST).'/oauth/authorize');

    expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
});

it('honors a preserved invitation destination reached before signing in', function (): void {
    $user = User::factory()->create();
    $invitation = WorkspaceInvitation::factory()->create(['email' => $user->email]);
    $rawToken = $invitation->issueToken();
    $invitation->save();

    $target = loginResponseFor($user, route('workspace-invitations.token.accept', ['token' => $rawToken]));

    expect($target)->toBe(route('workspace-invitations.token.accept', ['token' => $rawToken]));
});

it('honors a preserved email-change verification destination reached before signing in', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $intended = Filament::getVerifyEmailChangeUrl($user, 'new-address@example.com');

    $target = loginResponseFor($user, $intended);

    expect($target)->toBe($intended);
});

it('honors a preserved email-change block destination reached before signing in', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $verifyUrl = Filament::getVerifyEmailChangeUrl($user, 'new-address@example.com');
    parse_str((string) parse_url($verifyUrl, PHP_URL_QUERY), $query);
    $intended = Filament::getBlockEmailChangeVerificationUrl($user, 'new-address@example.com', (string) $query['signature']);

    $target = loginResponseFor($user, $intended);

    expect($target)->toBe($intended);
});

describe('login destinations - domain-routed panel', function (): void {
    beforeEach(function (): void {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        putenv('APP_PANEL_DOMAIN=app.example.com');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        // The rebuilt container reads the database name from the environment
        // again, losing the per-worker name, and it resolves the connection
        // during boot, so the restored name only takes effect after a purge.
        config(["database.connections.{$connection}.database" => $database]);
        DB::purge($connection);

        // LazilyRefreshDatabase armed its rollback on the container that was
        // just replaced. Without this the block's writes commit for real.
        $this->beginDatabaseTransaction();
    });

    afterEach(function (): void {
        putenv('APP_PANEL_DOMAIN');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });

    it('honors a relative destination into a workspace the user belongs to', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->currentWorkspace;

        $target = loginResponseFor($user, "/{$workspace->slug}/companies");

        expect($target)->toEndWith("/{$workspace->slug}/companies");
    });

    it('falls back to the dashboard for a foreign workspace on the panel domain', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $otherWorkspace = Workspace::factory()->create();

        $target = loginResponseFor($user, url()->getAppUrl($otherWorkspace->slug.'/companies'));

        expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
    });

    it('falls back to the dashboard for a workspace the user was removed from on the panel domain', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $revokedWorkspace = Workspace::factory()->create();
        $revokedWorkspace->users()->attach($user, ['role' => 'editor']);
        $revokedWorkspace->removeUser($user);

        $target = loginResponseFor($user, url()->getAppUrl($revokedWorkspace->slug.'/companies'));

        expect($target)->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));
    });
});
