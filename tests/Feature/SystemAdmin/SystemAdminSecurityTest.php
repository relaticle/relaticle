<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FAQRCode\Google2FA;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Ink\Models\Category;
use Relaticle\Ink\Models\Post;
use Relaticle\Ink\Models\Tag;
use Relaticle\SystemAdmin\Actions\Passkeys\DeletePasskey as SysadminDeletePasskey;
use Relaticle\SystemAdmin\Auth\StaffWebAuthn;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Pages\Auth\EditProfile;
use Relaticle\SystemAdmin\Filament\Pages\Settings\ManageAiSettings;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\EditUser;
use Relaticle\SystemAdmin\Http\Middleware\RequireSecondFactor;
use Relaticle\SystemAdmin\Http\Requests\PasskeyRegistrationRequest;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\PasskeyAssertionFixture;

mutates(SystemAdministrator::class, SystemAdministratorRole::class, RequireSecondFactor::class, SystemAdministratorPasskey::class);

it('rejects an injected staff role on the administrator profile', function (): void {
    $administrator = SystemAdministrator::factory()->administrator()->create();
    $this->actingAs($administrator, 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    livewire(EditProfile::class)
        ->set('data.role', SystemAdministratorRole::SuperAdministrator->value)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($administrator->refresh()->role)->toBe(SystemAdministratorRole::Administrator);
});

it('denies guest and customer blog gates for classes and records in every panel context', function (?string $panel): void {
    Filament::setCurrentPanel($panel);
    $customer = User::factory()->make();

    foreach ([Post::class, Category::class, new Post, new Category] as $target) {
        foreach (['viewAny', 'view', 'create', 'update', 'restore', 'restoreAny', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny'] as $ability) {
            expect(Gate::forUser(null)->allows($ability, $target))->toBeFalse();
            expect(Gate::forUser($customer)->allows($ability, $target))->toBeFalse();
        }
    }
})->with(['outside a panel' => null, 'customer panel' => 'app', 'staff panel' => 'sysadmin']);

describe('SystemAdmin Security', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
    });

    it('enforces complete authentication isolation', function () {
        $admin = SystemAdministrator::factory()->create();
        $user = User::factory()->create();

        expect($admin->canAccessPanel(Filament::getPanel('app')))->toBeFalse()
            ->and($user->canAccessPanel(Filament::getPanel('sysadmin')))->toBeFalse();

        $this->actingAs($admin, 'sysadmin');
        $this->assertAuthenticatedAs($admin, 'sysadmin');
        $this->assertGuest('web');
    });

    it('enforces role-based authorization', function () {
        $superAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $otherAdmin = SystemAdministrator::factory()->create([
            'role' => SystemAdministratorRole::SuperAdministrator,
        ]);

        $this->actingAs($superAdmin, 'sysadmin');

        expect(auth('sysadmin')->user()->can('create', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('viewAny', SystemAdministrator::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('update', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $otherAdmin))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('delete', $superAdmin))->toBeFalse();
    });

    it('redirects unauthenticated visitors to sysadmin login', function (string $route) {
        $this->get($route)->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'imports' => '/sysadmin/imports',
        'users' => '/sysadmin/users',
        'workspaces' => '/sysadmin/workspaces',
        'system-administrators' => '/sysadmin/system-administrators',
    ]);

    it('blocks regular app users from accessing sysadmin panel', function (string $route) {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get($route)
            ->assertRedirect('/sysadmin/login');
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'users' => '/sysadmin/users',
    ]);

    it('denies a customer every ability the sysadmin policies answer', function (string $model) {
        $user = User::factory()->create();

        expect($user->can('viewAny', $model))->toBeFalse()
            ->and($user->can('view', $model))->toBeFalse()
            ->and($user->can('create', $model))->toBeFalse()
            ->and($user->can('update', $model))->toBeFalse()
            ->and($user->can('delete', $model))->toBeFalse();
    })->with([
        'companies' => Company::class,
        'workspaces' => Workspace::class,
        'users' => User::class,
        'posts' => Post::class,
        'tags' => Tag::class,
    ]);

    it('blocks unverified sysadmin from accessing panel routes', function () {
        $unverifiedAdmin = SystemAdministrator::factory()->unverified()->create();

        $this->actingAs($unverifiedAdmin, 'sysadmin')
            ->get('/sysadmin')
            ->assertForbidden();
    });

    it('allows verified sysadmin to access panel routes', function () {
        $admin = SystemAdministrator::factory()->create();

        $this->actingAs($admin, 'sysadmin')
            ->get('/sysadmin/system-administrators')
            ->assertOk();
    });

});

describe('Administrator role', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

        $this->administrator = SystemAdministrator::factory()->administrator()->create();
        $this->actingAs($this->administrator, 'sysadmin');
    });

    it('reads every panel index it is allowed to see', function (string $route) {
        $this->get($route)->assertOk();
    })->with([
        'dashboard' => '/sysadmin',
        'companies' => '/sysadmin/companies',
        'users' => '/sysadmin/users',
        'workspaces' => '/sysadmin/workspaces',
        'subscriptions' => '/sysadmin/billing/subscriptions',
        'ai credit balances' => '/sysadmin/ai/credit-balances',
        'activities' => '/sysadmin/activity',
        'posts' => '/sysadmin/posts',
        'categories' => '/sysadmin/categories',
        'tags' => '/sysadmin/tags',
    ]);

    it('writes but never deletes', function (string $model) {
        $administrator = auth('sysadmin')->user();

        expect($administrator->can('viewAny', $model))->toBeTrue()
            ->and($administrator->can('view', $model))->toBeTrue()
            ->and($administrator->can('create', $model))->toBeTrue()
            ->and($administrator->can('update', $model))->toBeTrue()
            ->and($administrator->can('restore', $model))->toBeTrue()
            ->and($administrator->can('delete', $model))->toBeFalse()
            ->and($administrator->can('deleteAny', $model))->toBeFalse()
            ->and($administrator->can('forceDelete', $model))->toBeFalse()
            ->and($administrator->can('forceDeleteAny', $model))->toBeFalse();
    })->with([
        'companies' => Company::class,
        'people' => People::class,
        'opportunities' => Opportunity::class,
        'tasks' => Task::class,
        'notes' => Note::class,
        'users' => User::class,
        'workspaces' => Workspace::class,
        'posts' => Post::class,
        'categories' => Category::class,
        'tags' => Tag::class,
    ]);

    it('is offered no delete action on a record it may edit', function () {
        $user = User::factory()->withPersonalWorkspace()->create();

        livewire(EditUser::class, ['record' => $user->getKey()])
            ->assertOk()
            ->assertActionHidden(TestAction::make('delete'));
    });

    it('cannot reach the system administrators resource on any route', function (string $route) {
        $this->get($route)->assertForbidden();
    })->with([
        'index' => '/sysadmin/system-administrators',
        'create' => '/sysadmin/system-administrators/create',
    ]);

    it('cannot reach its own administrator record', function () {
        $this->get('/sysadmin/system-administrators/'.$this->administrator->getKey().'/edit')
            ->assertForbidden();

        expect(auth('sysadmin')->user()->can('update', $this->administrator))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('create', SystemAdministrator::class))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('delete', $this->administrator))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('deleteAny', SystemAdministrator::class))->toBeFalse()
            ->and(auth('sysadmin')->user()->can('viewAny', PersonalAccessToken::class))->toBeFalse();
    });

    it('keeps the writes that are not deletes', function () {
        $balance = AiCreditBalance::factory()->create();

        expect(auth('sysadmin')->user()->can('transfer', Subscription::class))->toBeTrue()
            ->and(auth('sysadmin')->user()->can('update', $balance))->toBeTrue();

        $this->get(ManageAiSettings::getUrl())->assertOk();
    });
});

describe('Second factor', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
    });

    it('sends an unenrolled administrator to the enrolment page instead of the panel', function (string $route) {
        $administrator = SystemAdministrator::factory()->unenrolled()->create();

        $this->actingAs($administrator, 'sysadmin')
            ->get($route)
            ->assertRedirect(Filament::getPanel('sysadmin')->getSetUpRequiredMultiFactorAuthenticationUrl());
    })->with([
        'dashboard' => '/sysadmin',
        'users' => '/sysadmin/users',
        'system-administrators' => '/sysadmin/system-administrators',
        'profile' => '/sysadmin/profile',
    ]);

    it('lets an unenrolled administrator reach the enrolment page and sign out', function () {
        $administrator = SystemAdministrator::factory()->unenrolled()->create();

        $this->actingAs($administrator, 'sysadmin')
            ->get(Filament::getPanel('sysadmin')->getSetUpRequiredMultiFactorAuthenticationUrl())
            ->assertOk();

        $this->post('/sysadmin/logout')->assertRedirect();
        $this->assertGuest('sysadmin');
    });

    it('guards every authenticated sysadmin route', function () {
        $panel = Filament::getPanel('sysadmin');

        $exempt = [
            $panel->getSetUpRequiredMultiFactorAuthenticationRouteName(),
            $panel->generateRouteName('auth.logout'),
        ];

        $unguarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'filament.sysadmin.'))
            ->filter(fn (RoutingRoute $route): bool => in_array(Authenticate::class, $route->gatherMiddleware(), strict: true))
            ->reject(fn (RoutingRoute $route): bool => in_array($route->getName(), $exempt, strict: true))
            ->reject(fn (RoutingRoute $route): bool => in_array(RequireSecondFactor::class, $route->gatherMiddleware(), strict: true))
            ->map(fn (RoutingRoute $route): string => (string) $route->getName())
            ->values();

        expect($unguarded)->toBeEmpty();
    });

    it('challenges a password sign-in for a one-time code', function () {
        $secret = app(Google2FA::class)->generateSecretKey(16);
        $administrator = SystemAdministrator::factory()->create([
            'app_authentication_secret' => $secret,
        ]);

        $component = livewire(Login::class)
            ->fillForm([
                'email' => $administrator->email,
                'password' => 'password',
            ])
            ->call('authenticate');

        $this->assertGuest('sysadmin');
        expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

        $component
            ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($secret))
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($administrator, 'sysadmin');
    });

    it('refuses a sign-in that answers the challenge with a wrong code', function () {
        $administrator = SystemAdministrator::factory()->create();

        livewire(Login::class)
            ->fillForm([
                'email' => $administrator->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->set('data.multiFactor.app.code', '000000')
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest('sysadmin');
    });

    it('spends a recovery code once', function () {
        $administrator = SystemAdministrator::factory()->create();
        $provider = AppAuthentication::make()->recoverable();
        $codes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($administrator, $codes);

        livewire(Login::class)
            ->fillForm([
                'email' => $administrator->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($administrator, 'sysadmin');
        expect($administrator->refresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(count($codes) - 1);
    });

    it('keeps the secret and the recovery codes out of serialisation', function () {
        $administrator = SystemAdministrator::factory()->create();
        AppAuthentication::make()->recoverable()->saveRecoveryCodes($administrator, ['code-one']);

        expect($administrator->refresh()->toArray())
            ->not->toHaveKey('app_authentication_secret')
            ->not->toHaveKey('app_authentication_recovery_codes');
    });
});

describe('Staff passkeys', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
    });

    it('keeps staff credentials in their own table', function () {
        $administrator = SystemAdministrator::factory()->create();

        expect($administrator->passkeys()->getRelated()->getTable())->toBe('system_administrator_passkeys')
            ->and($administrator->passkeys()->getRelated())->toBeInstanceOf(SystemAdministratorPasskey::class);
    });

    it('binds staff credentials to the panel host, not the customer relying party', function () {
        config()->set('app.sysadmin_domain', 'sysadmin.example.test');

        expect(SystemAdministratorPasskey::relyingPartyId())->toBe('sysadmin.example.test');

        config()->set('app.sysadmin_domain', null);
        config()->set('app.url', 'https://example.test');

        expect(SystemAdministratorPasskey::relyingPartyId())->toBe('example.test');
    });

    it('allows the sysadmin origin to complete a ceremony', function () {
        $panelUrl = Filament::getPanel('sysadmin')->getUrl();
        $parsed = parse_url((string) $panelUrl);
        $origin = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port']) ? ':'.$parsed['port'] : '');

        expect(StaffWebAuthn::allowedOrigin())->toBe($origin);
    });

    it('signs in a staff assertion minted on the sysadmin origin', function () {
        $administrator = SystemAdministrator::factory()->create();

        $options = $this->getJson('/sysadmin/passkeys/login/options')->json('options');
        $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

        $assertion = PasskeyAssertionFixture::build((string) $options['rpId'], StaffWebAuthn::allowedOrigin(), (string) $challenge);

        $administrator->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => PasskeyAssertionFixture::base64Url($assertion['credentialId']),
            'credential' => $assertion['storedCredential'],
        ]);

        $this->postJson('/sysadmin/passkeys/login', $assertion['payload'])->assertOk();

        $this->assertAuthenticatedAs($administrator, 'sysadmin');
    });

    it('refuses a staff assertion minted on the customer panel origin', function () {
        config()->set('passkeys.allowed_origins', [StaffWebAuthn::allowedOrigin(), 'https://app.example.test']);

        $administrator = SystemAdministrator::factory()->create();

        $options = $this->getJson('/sysadmin/passkeys/login/options')->json('options');
        $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);

        $assertion = PasskeyAssertionFixture::build((string) $options['rpId'], 'https://app.example.test', (string) $challenge);

        $administrator->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => PasskeyAssertionFixture::base64Url($assertion['credentialId']),
            'credential' => $assertion['storedCredential'],
        ]);

        $this->postJson('/sysadmin/passkeys/login', $assertion['payload'])->assertStatus(422);

        $this->assertGuest('sysadmin');
    });

    it('keeps the sysadmin origin and every path out of the customer allowed origins', function (array $app) {
        config()->set('app.url', $app['url']);
        config()->set('app.app_panel_domain', $app['app_panel_domain']);
        config()->set('app.sysadmin_domain', $app['sysadmin_domain']);

        $origins = (require base_path('config/fortify.php'))['passkeys']['allowed_origins'];

        expect($origins)->toBe($app['expected']);
    })->with([
        'trailing slash, no panel domains' => [[
            'url' => 'https://example.test/',
            'app_panel_domain' => null,
            'sysadmin_domain' => null,
            'expected' => ['https://example.test'],
        ]],
        'both panel domains' => [[
            'url' => 'https://example.test',
            'app_panel_domain' => 'app.example.test',
            'sysadmin_domain' => 'sysadmin.example.test',
            'expected' => ['https://app.example.test'],
        ]],
        'non-standard port is carried' => [[
            'url' => 'http://example.test:8000',
            'app_panel_domain' => 'app.example.test',
            'sysadmin_domain' => null,
            'expected' => ['http://app.example.test:8000'],
        ]],
    ]);

    it('builds the staff origin from the panel host, scheme and port', function (array $app) {
        config()->set('app.url', $app['url']);
        config()->set('app.sysadmin_domain', $app['sysadmin_domain']);

        expect(StaffWebAuthn::allowedOrigin())->toBe($app['expected']);
    })->with([
        'own domain' => [[
            'url' => 'https://example.test',
            'sysadmin_domain' => 'sysadmin.example.test',
            'expected' => 'https://sysadmin.example.test',
        ]],
        'trailing slash, path-routed' => [[
            'url' => 'https://example.test/',
            'sysadmin_domain' => null,
            'expected' => 'https://example.test',
        ]],
        'non-standard port is carried' => [[
            'url' => 'http://example.test:8000',
            'sysadmin_domain' => 'sysadmin.example.test',
            'expected' => 'http://sysadmin.example.test:8000',
        ]],
    ]);

    it('cannot derive the same webauthn handle as a customer with the same key', function () {
        $administrator = SystemAdministrator::factory()->create();
        $customer = User::factory()->create();

        expect($administrator->getPasskeyUserHandle())->not->toBe($customer->getPasskeyUserHandle());
    });

    it('offers passkey sign-in to a guest and refuses it to a signed-in administrator', function () {
        $this->getJson('/sysadmin/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure(['options' => ['challenge', 'rpId']]);

        $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin')
            ->get('/sysadmin/passkeys/login/options')
            ->assertRedirect();
    });

    it('scopes the ceremony to the sysadmin relying party', function () {
        $response = $this->getJson('/sysadmin/passkeys/login/options')->assertOk();

        expect($response->json('options.rpId'))->toBe(SystemAdministratorPasskey::relyingPartyId())
            ->and($response->json('options.userVerification'))->toBe('required');
    });

    it('refuses a malformed credential at the sysadmin sign-in', function () {
        $this->postJson('/sysadmin/passkeys/login', [
            'credential' => [
                'id' => 'Zm9v',
                'rawId' => 'Zm9v',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'e30', 'authenticatorData' => 'e30', 'signature' => 'e30'],
            ],
        ])->assertStatus(422);

        $this->assertGuest('sysadmin');
    });

    it('withholds passkey registration until the second factor is enrolled', function (string $route) {
        $this->actingAs(SystemAdministrator::factory()->unenrolled()->create(), 'sysadmin')
            ->get($route)
            ->assertRedirect(Filament::getPanel('sysadmin')->getSetUpRequiredMultiFactorAuthenticationUrl());
    })->with([
        'options' => '/sysadmin/passkeys/options',
    ]);

    it('refuses passkey registration to a guest', function () {
        $this->get('/sysadmin/passkeys/options')->assertRedirect('/sysadmin/login');
        $this->post('/sysadmin/passkeys')->assertRedirect('/sysadmin/login');
    });

    it('refuses to register a credential on a session that never answered the code prompt', function () {
        $administrator = SystemAdministrator::factory()->create();

        $this->actingAs($administrator, 'sysadmin')
            ->get('/sysadmin/passkeys/options')
            ->assertForbidden();

        $this->actingAs($administrator, 'sysadmin')
            ->post('/sysadmin/passkeys')
            ->assertForbidden();
    });

    it('refuses to register a credential on an expired code prompt', function () {
        $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin')
            ->withSession([PasskeyRegistrationRequest::GRANT_KEY => now()->subSecond()])
            ->get('/sysadmin/passkeys/options')
            ->assertForbidden();
    });

    it('issues registration options bound to the administrator', function () {
        $administrator = SystemAdministrator::factory()->create();

        $response = $this->actingAs($administrator, 'sysadmin')
            ->withSession([PasskeyRegistrationRequest::GRANT_KEY => now()->addMinutes(2)])
            ->getJson('/sysadmin/passkeys/options')
            ->assertOk();

        expect($response->json('options.rp.id'))->toBe(SystemAdministratorPasskey::relyingPartyId())
            ->and($response->json('options.user.name'))->toBe($administrator->email)
            ->and($response->json('options.authenticatorSelection.userVerification'))->toBe('required')
            ->and($response->json('options.authenticatorSelection.residentKey'))->toBe('required');
    });

    it('stores a verified staff passkey registration through the HTTP endpoint', function (): void {
        $administrator = SystemAdministrator::factory()->create();
        $this->actingAs($administrator, 'sysadmin')
            ->withSession([PasskeyRegistrationRequest::GRANT_KEY => now()->addMinutes(2)]);

        $options = $this->getJson('/sysadmin/passkeys/options')->assertOk()->json('options');
        $challenge = base64_decode(strtr($options['challenge'], '-_', '+/'), true);
        $credential = PasskeyAssertionFixture::registration($options['rp']['id'], StaffWebAuthn::allowedOrigin(), $challenge);

        $this->postJson('/sysadmin/passkeys', ['name' => 'Work laptop', 'credential' => $credential])->assertCreated();

        expect($administrator->passkeys()->sole()->credential_id)->toBe($credential['id']);
    });

    it('removes only the administrator own credential', function () {
        $administrator = SystemAdministrator::factory()->create();
        $other = SystemAdministrator::factory()->create();

        $passkey = $other->passkeys()->create([
            'name' => 'Other laptop',
            'credential_id' => 'other-credential',
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);

        expect(fn () => app(SysadminDeletePasskey::class)->execute($administrator, $passkey))
            ->toThrow(HttpException::class);

        expect(SystemAdministratorPasskey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
    });

    it('drops staff credentials with the administrator', function () {
        $administrator = SystemAdministrator::factory()->create();

        $administrator->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'cascade-credential',
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
        ]);

        $administrator->delete();

        expect(SystemAdministratorPasskey::query()->where('credential_id', 'cascade-credential')->exists())->toBeFalse();
    });
});

describe('Passkey management proof of identity', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('sysadmin');
        config()->set('app.sysadmin_domain', 'sysadmin.example.test');
    });

    it('never offers to turn the required factor off, but keeps recovery codes regenerable', function () {
        $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');

        $names = array_map(
            fn (Action $action): string => $action->getName(),
            Filament::getPanel('sysadmin')->getMultiFactorAuthenticationProviders()['app']->getActions(),
        );

        expect($names)->not->toContain('disableAppAuthentication')
            ->and($names)->toContain('regenerateAppAuthenticationRecoveryCodes');
    });

    it('offers no passkey registration while staff and customer credentials share a relying party', function (array $app) {
        config()->set('app.sysadmin_domain', $app['sysadmin_domain']);
        config()->set('passkeys.relying_party_id', $app['customer_relying_party_id']);
        $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');

        expect(SystemAdministratorPasskey::hasDedicatedRelyingParty())->toBeFalse();

        livewire(EditProfile::class)->assertActionHidden(TestAction::make('registerPasskey'));
    })->with([
        'path-routed panel falls back to the app host' => [[
            'sysadmin_domain' => null,
            'customer_relying_party_id' => 'app.example.test',
        ]],
        'staff relying party is a parent of the customer host' => [[
            'sysadmin_domain' => 'example.test',
            'customer_relying_party_id' => 'app.example.test',
        ]],
        'both panels on one host' => [[
            'sysadmin_domain' => 'example.test',
            'customer_relying_party_id' => 'example.test',
        ]],
    ]);

    it('offers passkey registration once the panel has its own relying party', function () {
        $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');

        expect(SystemAdministratorPasskey::hasDedicatedRelyingParty())->toBeTrue();

        livewire(EditProfile::class)->assertActionVisible(TestAction::make('registerPasskey'));
    });

    it('refuses an authenticator code that was already spent on this account', function () {
        $secret = app(Google2FA::class)->generateSecretKey(16);
        $administrator = SystemAdministrator::factory()->create(['app_authentication_secret' => $secret]);
        $this->actingAs($administrator, 'sysadmin');
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        livewire(EditProfile::class)
            ->callAction(TestAction::make('registerPasskey'), ['code' => $code])
            ->assertHasNoActionErrors();

        expect(session()->has(PasskeyRegistrationRequest::GRANT_KEY))->toBeTrue();

        session()->forget(PasskeyRegistrationRequest::GRANT_KEY);

        livewire(EditProfile::class)
            ->callAction(TestAction::make('registerPasskey'), ['code' => $code])
            ->assertHasActionErrors(['code']);

        expect(session()->has(PasskeyRegistrationRequest::GRANT_KEY))->toBeFalse();
    });

    it('stops guessing authenticator codes after five attempts', function () {
        $administrator = SystemAdministrator::factory()->create();
        $this->actingAs($administrator, 'sysadmin');

        foreach (range(1, 5) as $ignored) {
            livewire(EditProfile::class)
                ->callAction(TestAction::make('registerPasskey'), ['code' => '000000'])
                ->assertHasActionErrors(['code']);
        }

        livewire(EditProfile::class)->callAction(TestAction::make('registerPasskey'), [
            'code' => app(Google2FA::class)->getCurrentOtp($administrator->getAppAuthenticationSecret()),
        ]);

        expect(session()->has(PasskeyRegistrationRequest::GRANT_KEY))->toBeFalse();
    });

});
