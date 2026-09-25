<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\User;
use Filament\Auth\Pages\Login as StaffLogin;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\Support\View\ViewManager;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FAQRCode\Google2FA;
use Relaticle\Ink\Models\Post;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ViewUser;
use Relaticle\SystemAdmin\Http\Middleware\EnsureAuthenticationContext;
use Relaticle\SystemAdmin\Http\Middleware\IsolateAuthenticationSession;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Helpers\PasskeyAssertionFixture;

mutates(IsolateAuthenticationSession::class, EnsureAuthenticationContext::class);

beforeEach(function (): void {
    config()->set([
        'app.url' => 'https://example.test',
        'app.app_panel_domain' => 'app.example.test',
        'app.sysadmin_domain' => 'staff.example.test',
        'session.driver' => 'database',
        'session.domain' => '.example.test',
        'session.secure' => true,
        'system-admin.session.driver' => 'database',
    ]);

    $this->initialFilamentView = clone FilamentView::getFacadeRoot();
    $routes = new RouteCollection;

    foreach (app('router')->getRoutes() as $route) {
        $route = clone $route;
        $route->compiled = null;
        $route->flushController();

        foreach (['app' => 'app.example.test', 'sysadmin' => 'staff.example.test'] as $panel => $domain) {
            if ($route->named("filament.{$panel}.*")) {
                $route->domain($domain)->setUri(preg_replace("/^{$panel}(?:\/|$)/", '', $route->uri()) ?: '/');
            }
        }

        $routes->add($route);
    }

    app('router')->setRoutes($routes);
    app('url')->setRoutes($routes);
    Filament::getPanel('app')->domain('app.example.test')->path('');
    Filament::getPanel('sysadmin')->domain('staff.example.test')->path('');
});

/**
 * @param  array<string, array<string, string>>  $cookies
 * @param  array<string, mixed>  $data
 * @return TestResponse<Response>
 */
function isolatedAuthRequest(array &$cookies, string $method, string $url, array $data = []): TestResponse
{
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('cookie');
    Cookie::clearResolvedInstance('cookie');
    app()->forgetInstance('redirect');
    Redirect::clearResolvedInstance('redirect');
    app()->forgetInstance(ResponseFactory::class);
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    Auth::shouldUse('web');
    app()->forgetInstance('filament');
    Filament::clearResolvedInstance('filament');
    app()->instance(ViewManager::class, clone test()->initialFilamentView);
    FilamentView::clearResolvedInstance(ViewManager::class);
    app('livewire')->flushState();

    $host = parse_url($url, PHP_URL_HOST);
    $sent = [];

    foreach ($cookies as $domain => $values) {
        if ($domain === $host || (str_starts_with($domain, '.') && str_ends_with($host, $domain))) {
            $sent = array_replace($sent, $values);
        }
    }

    $response = test()->call($method, $url, [], $sent, [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        ...(str_ends_with($url, '/update') ? ['HTTP_X_LIVEWIRE' => 'true'] : []),
    ], json_encode($data, JSON_THROW_ON_ERROR));

    foreach ($response->headers->getCookies() as $cookie) {
        $domain = $cookie->getDomain() ?: $host;
        $cookies[$domain][$cookie->getName()] = $cookie->getValue();

        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
            unset($cookies[$domain][$cookie->getName()]);
        }
    }

    Exceptions::assertNothingReported();

    return $response;
}

/** @param array<string, array<string, string>> $cookies */
function signInIsolatedStaff(array &$cookies, SystemAdministrator $administrator, bool $remember = false): void
{
    $options = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/passkeys/login/options')
        ->assertOk()->json('options');
    $challenge = base64_decode(strtr($options['challenge'], '-_', '+/'), true);
    $assertion = PasskeyAssertionFixture::build($options['rpId'], 'https://staff.example.test', $challenge);

    $administrator->passkeys()->create([
        'name' => 'Work laptop',
        'credential_id' => PasskeyAssertionFixture::base64Url($assertion['credentialId']),
        'credential' => $assertion['storedCredential'],
    ]);

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/passkeys/login', [...$assertion['payload'], 'remember' => $remember])
        ->assertOk();
}

final class StaffSessionRedirectController extends Controller
{
    public function __construct(private readonly Redirector $redirector) {}

    public function __invoke(): RedirectResponse
    {
        return $this->redirector->to('https://staff.example.test/session-flash')->with('notice', 'Saved');
    }
}

it('persists staff flash messages when a controller constructor resolves the redirector', function (): void {
    Route::domain('staff.example.test')->middleware('web')->group(function (): void {
        Route::get('/session-redirect', StaffSessionRedirectController::class);
        Route::get('/session-flash', fn (Request $request): array => ['notice' => $request->session()->get('notice')]);
    });
    $cookies = [];

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/session-redirect')
        ->assertRedirect('https://staff.example.test/session-flash');

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/session-flash')
        ->assertOk()->assertJson(['notice' => 'Saved']);
});

it('persists staff flash messages on the first HTTP request after application boot', function (): void {
    Route::middleware('web')->group(base_path('routes/web.php'));

    Route::domain('staff.example.test')->middleware('web')->group(function (): void {
        Route::get('/session-redirect', StaffSessionRedirectController::class);
        Route::get('/session-flash', fn (Request $request): array => ['notice' => $request->session()->get('notice')]);
    });
    $cookies = [];
    $response = $this->getJson('https://staff.example.test/session-redirect')
        ->assertRedirect('https://staff.example.test/session-flash');
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getDomain() ?: 'staff.example.test'][$cookie->getName()] = $cookie->getValue();
    }

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/session-flash')
        ->assertOk()->assertJson(['notice' => 'Saved']);
});

it('gives staff a secure host-only cookie and separate database storage', function (): void {
    $cookies = [];
    $customer = isolatedAuthRequest($cookies, 'GET', 'https://example.test/passkeys/login/options')->assertOk();
    $staff = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/passkeys/login/options')->assertOk();
    $customerCookie = $customer->getCookie(config('session.cookie'));
    $staffCookie = $staff->getCookie('__Host-'.config('system-admin.session.cookie'));

    expect($customerCookie)->not->toBeNull()
        ->and($staffCookie)->not->toBeNull()
        ->and($staffCookie->getDomain())->toBeNull()
        ->and($staffCookie->isSecure())->toBeTrue()
        ->and($staffCookie->isHttpOnly())->toBeTrue()
        ->and($staffCookie->getSameSite())->toBe('strict');

    expect(DB::table('sessions')->where('id', $customerCookie->getValue())->exists())->toBeTrue()
        ->and(DB::table('system_administrator_sessions')->where('id', $staffCookie->getValue())->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', $staffCookie->getValue())->exists())->toBeFalse();
});

it('ends the staff session with the browser only when configured to', function (bool $expireOnClose): void {
    config()->set([
        'system-admin.session.expire_on_close' => $expireOnClose,
        'system-admin.session.lifetime' => 480,
    ]);
    $this->freezeTime();
    $cookies = [];
    $response = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/passkeys/login/options')->assertOk();

    expect($response->getCookie('__Host-'.config('system-admin.session.cookie'))->getExpiresTime())
        ->toBe($expireOnClose ? 0 : now()->addMinutes(480)->getTimestamp());
})->with([
    'on browser close' => [true],
    'after the idle lifetime' => [false],
]);

it('isolates staff sessions when the configured hostname contains uppercase letters', function (): void {
    config()->set('app.sysadmin_domain', 'Staff.Example.test');
    foreach (app('router')->getRoutes() as $route) {
        if ($route->getDomain() === 'staff.example.test') {
            $route->domain('Staff.Example.test');
        }
    }
    $cookies = [];
    $response = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/passkeys/login/options')->assertOk();
    $cookie = $response->getCookie('__Host-'.config('system-admin.session.cookie'));

    expect($cookie)->not->toBeNull();
    expect(DB::table('system_administrator_sessions')->where('id', $cookie->getValue())->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', $cookie->getValue())->exists())->toBeFalse();
});

it('keeps staff signed in through customer password login and logout', function (string $driver): void {
    config()->set('system-admin.session.driver', $driver);
    $directory = sys_get_temp_dir().'/relaticle-staff-'.Str::uuid();
    config()->set('system-admin.session.files', $directory);
    $this->beforeApplicationDestroyed(function () use ($directory): void {
        File::deleteDirectory($directory);
    });
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $customer = User::factory()->withWorkspace()->create();
    signInIsolatedStaff($cookies, $administrator);

    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk()->assertJson(['two_factor' => false]);

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertRedirect('https://staff.example.test');
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/logout')->assertRedirect();
    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertRedirect('https://staff.example.test');

    expect(DB::table('sessions')->where('user_id', $administrator->id)->exists())->toBeFalse()
        ->and(DB::table('system_administrator_sessions')->where('user_id', $administrator->id)->exists())->toBe($driver === 'database');
})->with(['database', 'file']);

it('refuses customer password authentication on the staff hostname', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertNotFound();

    expect(DB::table('system_administrator_sessions')->where('user_id', $customer->id)->exists())->toBeFalse();
});

it('rejects a saved staff page snapshot on the customer host after staff logout', function (string $page, string $component): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $customer = User::factory()->withWorkspace()->create();
    signInIsolatedStaff($cookies, $administrator);

    $response = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test'.$page)->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
        ->first(fn (string $value): bool => str_contains(json_decode($value, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], $component));

    expect($snapshot)->not->toBeNull();
    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/logout')->assertRedirect();
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk();

    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test'.route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertStatus(419);
})->with([
    'model catalog' => ['/ai-models', 'ManageAiSettings'],
    'dashboard' => ['/', '\\Pages\\Dashboard'],
    'lazy widget' => ['/', 'PlatformGrowthStatsWidget'],
    'shared global search' => ['/', 'GlobalSearch'],
    'shared notifications' => ['/', 'DatabaseNotifications'],
    'shared topbar' => ['/', 'Topbar'],
]);

it('keeps the customer signed in through staff login and logout with identical account ids', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    $administrator = SystemAdministrator::factory()->create(['id' => $customer->id]);

    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk();
    signInIsolatedStaff($cookies, $administrator);
    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/logout')->assertRedirect();
    isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/passkeys/confirm/options')->assertOk();

    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeTrue()
        ->and(DB::table('system_administrator_sessions')->where('user_id', $administrator->id)->exists())->toBeFalse();
});

it('does not restore customer authentication from a parent-domain remember cookie on the staff host', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
        'remember' => true,
    ])->assertOk();

    $recaller = Auth::guard('web')->getRecallerName();
    expect($cookies['.example.test'])->toHaveKey($recaller);

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertOk();
    $sessions = DB::table('system_administrator_sessions')->get();

    expect($sessions)->toHaveCount(1)
        ->and($sessions->sole()->user_id)->toBeNull();
    $payload = unserialize(base64_decode($sessions->sole()->payload));
    expect($payload)->not->toHaveKey(Auth::guard('web')->getName());
});

it('ignores a legacy staff remember cookie outside the staff host', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    signInIsolatedStaff($cookies, $administrator, remember: true);
    $recaller = Auth::guard('sysadmin')->getRecallerName();
    $cookies['.example.test'][$recaller] = $cookies['staff.example.test'][$recaller];

    isolatedAuthRequest($cookies, 'GET', 'https://example.test/passkeys/login/options')->assertOk();

    expect(DB::table('sessions')->where('user_id', $administrator->id)->exists())->toBeFalse();
    $payload = unserialize(base64_decode(DB::table('sessions')->sole()->payload));
    expect($payload)->not->toHaveKey(Auth::guard('sysadmin')->getName());
});

it('serves staff blog previews while still requiring a valid signature', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $post = Post::factory()->draft()->create();
    signInIsolatedStaff($cookies, $administrator);
    $url = $post->getUrl();

    expect(parse_url($url, PHP_URL_HOST))->toBe('staff.example.test');
    isolatedAuthRequest($cookies, 'GET', $url)->assertOk()->assertSee($post->title)->assertSee('Edit Post');
    isolatedAuthRequest($cookies, 'GET', $url.'&changed=1')->assertForbidden();
});

it('ignores staff authentication retained in a legacy customer session', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    $administrator = SystemAdministrator::factory()->create();
    $post = Post::factory()->draft()->create();
    $response = isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk();
    $id = $response->getCookie(config('session.cookie'))->getValue();
    $payload = unserialize(base64_decode(DB::table('sessions')->where('id', $id)->value('payload')));
    $payload[Auth::guard('sysadmin')->getName()] = $administrator->id;
    DB::table('sessions')->where('id', $id)->update(['payload' => base64_encode(serialize($payload))]);

    isolatedAuthRequest($cookies, 'GET', $post->getUrl())->assertOk()->assertDontSee('Edit Post');
    $this->assertGuest('sysadmin');
    $stored = unserialize(base64_decode(DB::table('sessions')->where('id', $id)->value('payload')));
    expect($stored)->not->toHaveKey(Auth::guard('sysadmin')->getName());
    isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/passkeys/confirm/options')->assertOk();
});

it('redirects staff published blog links to the public hostname', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $post = Post::factory()->published()->create();
    signInIsolatedStaff($cookies, $administrator);

    isolatedAuthRequest($cookies, 'GET', $post->getUrl())
        ->assertStatus(301)->assertRedirect('https://example.test/blog/'.$post->slug);
});

it('redirects public navigation from the staff hostname', function (string $route, array $parameters): void {
    $cookies = [];
    $path = route($route, $parameters, absolute: false);

    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test'.$path)
        ->assertStatus(301)->assertRedirect('https://example.test'.$path);
})->with([
    'pricing' => ['pricing', []],
    'blog' => ['blog.index', []],
    'category' => ['blog.category', ['slug' => 'announcements']],
    'documentation' => ['documentation.index', []],
    'help' => ['help.index', []],
]);

it('limits the staff development login link to the local environment', function (string $environment): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    config()->set('login-link.allowed_hosts', ['staff.example.test']);
    $this->app->detectEnvironment(fn (): string => $environment);
    $this->beforeApplicationDestroyed(function (): void {
        Request::setTrustedHosts([]);
    });
    $this->withoutMiddleware(PreventRequestForgery::class);

    $response = isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/laravel-login-link-login', [
        'email' => $administrator->email,
        'guard' => 'sysadmin',
        'user_model' => SystemAdministrator::class,
        'redirect_url' => 'https://staff.example.test/',
    ]);

    if ($environment === 'local') {
        $response->assertRedirect('https://staff.example.test/');
        isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertRedirect();
    } else {
        $response->assertNotFound();
        expect(DB::table('system_administrator_sessions')->whereNotNull('user_id')->exists())->toBeFalse();
    }
})->with(['local', 'production']);

it('rejects a customer login snapshot on the staff host before authenticating', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    $page = isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/login')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
        ->first(fn (string $value): bool => json_decode($value, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] === Login::class);
    expect($snapshot)->not->toBeNull();

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test'.route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.email' => $customer->email, 'data.password' => 'password'],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertStatus(419);

    expect(DB::table('system_administrator_sessions')->whereNotNull('user_id')->exists())->toBeFalse();
});

it('requires the native staff password and MFA challenge while keeping the customer session', function (): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    $administrator = SystemAdministrator::factory()->create();
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk();

    $page = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
        ->first(fn (string $value): bool => json_decode($value, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] === StaffLogin::class);
    expect($snapshot)->not->toBeNull();
    $endpoint = 'https://staff.example.test'.route('default-livewire.update', absolute: false);
    $challenge = isolatedAuthRequest($cookies, 'POST', $endpoint, [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.email' => $administrator->email, 'data.password' => 'password'],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertOk();
    expect(DB::table('system_administrator_sessions')->whereNotNull('user_id')->exists())->toBeFalse();

    $code = resolve(Google2FA::class)->getCurrentOtp($administrator->app_authentication_secret);
    isolatedAuthRequest($cookies, 'POST', $endpoint, [
        'components' => [[
            'snapshot' => $challenge->json('components.0.snapshot'),
            'updates' => ['data.multiFactor.app.code' => $code],
            'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
        ]],
    ])->assertOk()->assertJsonPath('components.0.effects.redirect', 'https://staff.example.test');
    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test')->assertOk()->assertSee('Dashboard');
    isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/passkeys/confirm/options')->assertOk();
    expect(DB::table('system_administrator_sessions')->where('user_id', $administrator->id)->exists())->toBeTrue();
});

it('rejects the other hosts CSRF token in both directions', function (): void {
    $cookies = [];
    $user = User::factory()->withWorkspace()->create();
    $administrator = SystemAdministrator::factory()->create();
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
    signInIsolatedStaff($cookies, $administrator);
    $this->app->detectEnvironment(fn (): string => 'local');
    $customer = isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/passkeys/confirm/options')->assertOk();
    $staff = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/login')->assertRedirect();
    $customerId = $customer->getCookie(config('session.cookie'))->getValue();
    $staffId = $staff->getCookie('__Host-'.config('system-admin.session.cookie'))->getValue();
    $customerToken = unserialize(base64_decode(DB::table('sessions')->where('id', $customerId)->value('payload')))['_token'];
    $staffToken = unserialize(base64_decode(DB::table('system_administrator_sessions')->where('id', $staffId)->value('payload')))['_token'];

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test/logout', ['_token' => $customerToken])->assertStatus(419);
    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/logout', ['_token' => $staffToken])->assertStatus(419);
});

it('loads staff lazy widgets normally and rejects their signed mount parameters on the customer host', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    signInIsolatedStaff($cookies, $administrator);
    $page = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/')->assertOk();
    $html = html_entity_decode($page->getContent(), ENT_QUOTES);
    $matched = preg_match("/__lazyLoad\\('([^']+)'\\)/", $html, $lazy);
    expect($matched)->toBe(1);
    $inner = json_decode(base64_decode($lazy[1]), true, flags: JSON_THROW_ON_ERROR);
    $component = $inner['data']['forComponent'];
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $outer = collect($matches[1])
        ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
        ->first(fn (string $value): bool => json_decode($value, true, flags: JSON_THROW_ON_ERROR)['memo']['name'] === $component);
    expect($outer)->not->toBeNull();

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test'.route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => $outer,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '__lazyLoad', 'params' => [$lazy[1]]]],
        ]],
    ])->assertOk()->assertJsonStructure(['components' => [['effects' => ['html']]]]);

    isolatedAuthRequest($cookies, 'POST', 'https://app.example.test'.route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => json_encode($inner, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [],
        ]],
    ])->assertStatus(419);
});

it('restores customer session configuration after a failed staff request', function (): void {
    $cookies = [];
    $original = config('session');
    app('auth.driver');
    isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/passkeys/options')->assertUnauthorized();
    expect(config('session'))->toBe($original)
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(app('auth.driver')->getName())->toBe(Auth::guard('web')->getName());

    $response = isolatedAuthRequest($cookies, 'GET', 'https://example.test/passkeys/login/options')->assertOk();
    expect($response->getCookie($original['cookie'])->getDomain())->toBe('.example.test');
});

it('removes foreign authentication from protected panel requests', function (string $context): void {
    $cookies = [];
    $customer = User::factory()->withWorkspace()->create();
    $administrator = SystemAdministrator::factory()->create();
    $customerResponse = isolatedAuthRequest($cookies, 'POST', 'https://app.example.test/login', [
        'email' => $customer->email,
        'password' => 'password',
    ])->assertOk();
    signInIsolatedStaff($cookies, $administrator);
    $staffResponse = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/')->assertOk();
    $isStaff = $context === 'sysadmin';
    $response = $isStaff ? $staffResponse : $customerResponse;
    $cookie = $isStaff ? '__Host-'.config('system-admin.session.cookie') : config('session.cookie');
    $table = $isStaff ? 'system_administrator_sessions' : 'sessions';
    $foreignGuard = $isStaff ? 'web' : 'sysadmin';
    $id = $response->getCookie($cookie)->getValue();
    $payload = unserialize(base64_decode(DB::table($table)->where('id', $id)->value('payload')));
    $payload[Auth::guard($foreignGuard)->getName()] = $isStaff ? $customer->id : $administrator->id;
    DB::table($table)->where('id', $id)->update(['payload' => base64_encode(serialize($payload))]);

    isolatedAuthRequest($cookies, 'GET', $isStaff
        ? 'https://staff.example.test/'
        : 'https://app.example.test/'.$customer->currentWorkspace->slug.'/companies')->assertOk();

    $this->assertGuest($foreignGuard);
    $stored = unserialize(base64_decode(DB::table($table)->where('id', $id)->value('payload')));
    expect($stored)->not->toHaveKey(Auth::guard($foreignGuard)->getName());
})->with(['web', 'sysadmin']);

it('requires second factor enrollment on an existing staff Livewire page', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    signInIsolatedStaff($cookies, $administrator);
    $page = isolatedAuthRequest($cookies, 'GET', 'https://staff.example.test/')->assertOk();
    preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
        ->first(fn (string $value): bool => str_contains(json_decode($value, true, flags: JSON_THROW_ON_ERROR)['memo']['name'], 'Dashboard'));
    expect($snapshot)->not->toBeNull();
    $administrator->update(['app_authentication_secret' => null]);

    isolatedAuthRequest($cookies, 'POST', 'https://staff.example.test'.route('default-livewire.update', absolute: false), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ])->assertRedirect(Filament::getPanel('sysadmin')->getSetUpRequiredMultiFactorAuthenticationUrl());
});

function mintImpersonationLink(SystemAdministrator $administrator, User $customer): string
{
    Filament::setCurrentPanel('sysadmin');
    test()->actingAs($administrator, 'sysadmin');

    $exceptions = Exceptions::getFacadeRoot();
    $handler = $exceptions->handler();

    $link = livewire(ViewUser::class, ['record' => $customer->getKey()])
        ->callAction('impersonate')
        ->effects['redirect'];

    $exceptions->setHandler($handler);

    return $link;
}

it('hands an impersonation from the staff host to the customer host', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $customer = User::factory()->withWorkspace()->create();

    signInIsolatedStaff($cookies, $administrator);
    $link = mintImpersonationLink($administrator, $customer);

    expect(parse_url($link, PHP_URL_HOST))->toBe('app.example.test');

    isolatedAuthRequest($cookies, 'GET', $link)->assertRedirect('https://app.example.test');
    isolatedAuthRequest($cookies, 'GET', 'https://app.example.test/passkeys/confirm/options')->assertOk();

    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeTrue()
        ->and(DB::table('system_administrator_sessions')->where('user_id', $administrator->id)->exists())->toBeTrue();
});

it('refuses the impersonation link on the staff host and a second use on the customer host', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $customer = User::factory()->withWorkspace()->create();

    signInIsolatedStaff($cookies, $administrator);
    $link = mintImpersonationLink($administrator, $customer);

    isolatedAuthRequest($cookies, 'GET', str_replace('app.example.test', 'staff.example.test', $link))->assertNotFound();
    isolatedAuthRequest($cookies, 'GET', $link)->assertRedirect();

    $replayed = [];
    isolatedAuthRequest($replayed, 'GET', $link)->assertForbidden();

    expect(DB::table('sessions')->where('user_id', $customer->id)->count())->toBe(1);
});

it('returns to the staff host on stop and credits both records to the administrator', function (): void {
    $cookies = [];
    $administrator = SystemAdministrator::factory()->create();
    $customer = User::factory()->withWorkspace()->create();

    signInIsolatedStaff($cookies, $administrator);
    isolatedAuthRequest($cookies, 'GET', mintImpersonationLink($administrator, $customer))->assertRedirect();

    isolatedAuthRequest($cookies, 'DELETE', 'https://app.example.test/impersonate')
        ->assertRedirect('https://staff.example.test/users');

    $events = Activity::withoutGlobalScope(WorkspaceScope::class)
        ->whereIn('event', ['impersonation_started', 'impersonation_stopped'])
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('causer_id')->unique()->all())->toBe([$administrator->getKey()])
        ->and(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeFalse();
});
