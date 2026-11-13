<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\ResetPassword;
use App\Listeners\Auth\PasswordGuessLimitListener;
use App\Models\User;
use App\Models\UserSocialAccount;
use Filament\Auth\Pages\Login as SysadminLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkey;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\PasskeyAssertionFixture;

mutates(PasswordGuessLimitListener::class);

function passwordAttemptFromIp(object $test, string $email, string $password, int $ipSuffix): TestResponse
{
    return $test
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$ipSuffix])
        ->postJson(route('login.store'), ['email' => $email, 'password' => $password]);
}

function guessesAcrossIps(object $test, string $email, int $count): void
{
    foreach (range(1, $count) as $ipSuffix) {
        passwordAttemptFromIp($test, $email, 'wrong-password', $ipSuffix)->assertUnprocessable();
    }
}

function panelPasswordAttempt(object $test, string $email, string $password, int $ipSuffix): Testable
{
    $test->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$ipSuffix]);

    return livewire(Login::class)
        ->fillForm(['email' => $email])
        ->call('authenticate')
        ->assertSet('authMethod', 'password')
        ->fillForm(['password' => $password])
        ->call('authenticate');
}

test('guesses against one account from many ips hit the per-account cap', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 10);

    passwordAttemptFromIp($this, $user->email, 'password', 11)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

test('the throttle message is the existing login throttle copy', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 10);

    $message = passwordAttemptFromIp($this, $user->email, 'password', 11)->json('errors.email.0');

    expect($message)->toMatch('/^Too many login attempts\. Please try again in \d+ seconds\.$/');
});

test('an unregistered email is throttled with the same response as a registered one', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 10);
    guessesAcrossIps($this, 'nobody@example.com', 10);

    $registered = passwordAttemptFromIp($this, $user->email, 'password', 11);
    $unregistered = passwordAttemptFromIp($this, 'nobody@example.com', 'password', 11);

    expect($unregistered->getStatusCode())->toBe($registered->getStatusCode())
        ->and(array_keys($unregistered->json()))->toBe(array_keys($registered->json()))
        ->and(array_keys($unregistered->json('errors')))->toBe(array_keys($registered->json('errors')))
        ->and(preg_replace('/\d+/', 'N', $unregistered->json('errors.email.0')))
        ->toBe(preg_replace('/\d+/', 'N', $registered->json('errors.email.0')));
});

test('case and whitespace variants of the email share one counter', function (): void {
    $user = User::factory()->withWorkspace()->create();

    foreach (range(1, 10) as $ipSuffix) {
        passwordAttemptFromIp($this, '  '.strtoupper($user->email).' ', 'wrong-password', $ipSuffix);
    }

    passwordAttemptFromIp($this, $user->email, 'password', 11)->assertJsonValidationErrors('email');
    $this->assertGuest('web');
});

test('the panel login page shares the per-account counter with the fortify route', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 9);

    panelPasswordAttempt($this, $user->email, 'wrong-password', 10)
        ->assertHasErrors(['data.email']);

    panelPasswordAttempt($this, $user->email, 'password', 11)
        ->assertHasErrors(['data.email'])
        ->assertNoRedirect();

    $this->assertGuest('web');
});

test('a different account is unaffected by a locked one', function (): void {
    $locked = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $locked->email, 10);

    passwordAttemptFromIp($this, $other->email, 'password', 11)->assertOk();

    $this->assertAuthenticatedAs($other);
});

test('a successful login clears the account counter', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 9);
    passwordAttemptFromIp($this, $user->email, 'password', 10)->assertOk();
    $this->assertAuthenticatedAs($user);

    Auth::guard('web')->logout();
    $this->flushSession();

    guessesAcrossIps($this, $user->email, 9);
    passwordAttemptFromIp($this, $user->email, 'password', 10)->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('a locked account that resets its password signs in immediately', function (): void {
    $user = User::factory()->withWorkspace()->create();
    guessesAcrossIps($this, $user->email, 10);
    passwordAttemptFromIp($this, $user->email, 'password', 11)->assertJsonValidationErrors('email');

    $token = Password::broker('users')->createToken($user);

    livewire(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'new-secure-password',
        ])
        ->call('resetPassword')
        ->assertRedirect();

    Auth::guard('web')->logout();
    $this->flushSession();

    passwordAttemptFromIp($this, $user->email, 'new-secure-password', 12)->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('the short window expires so a locked owner can retry within minutes', function (): void {
    $user = User::factory()->withWorkspace()->create();

    guessesAcrossIps($this, $user->email, 10);
    passwordAttemptFromIp($this, $user->email, 'password', 11)->assertJsonValidationErrors('email');

    $this->travelTo(now()->addMinutes(16));

    passwordAttemptFromIp($this, $user->email, 'password', 12)->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('a locked account can still sign in with a passkey', function (): void {
    $user = User::factory()->withWorkspace()->create();
    guessesAcrossIps($this, $user->email, 10);

    $options = $this->getJson(route('passkey.login-options'))->json('options');
    $challenge = base64_decode(strtr((string) $options['challenge'], '-_', '+/'), true);
    $assertion = PasskeyAssertionFixture::build((string) $options['rpId'], config('fortify.passkeys.allowed_origins')[0], (string) $challenge);

    Passkey::create([
        'user_id' => $user->id,
        'name' => 'Test',
        'credential_id' => PasskeyAssertionFixture::base64Url($assertion['credentialId']),
        'credential' => $assertion['storedCredential'],
    ]);

    $this->postJson(route('passkey.login'), $assertion['payload'])->assertOk();

    $this->assertAuthenticatedAs($user);
});

test('a locked account can still sign in with a linked social account', function (): void {
    $user = User::factory()->withWorkspace()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'locked-google-id',
    ]);
    guessesAcrossIps($this, $user->email, 10);

    $provider = new SocialiteUser;
    $provider->id = 'locked-google-id';
    $provider->name = $user->name;
    $provider->email = $user->email;
    Socialite::fake(SocialiteProvider::GOOGLE->value, $provider);

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('the sysadmin password login is throttled per account too', function (): void {
    $admin = SystemAdministrator::factory()->create();
    Filament::setCurrentPanel('sysadmin');

    foreach (range(1, 10) as $attempt) {
        if ($attempt % 5 === 1) {
            $this->travel(2)->minutes();
        }

        livewire(SysadminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'wrong-password'])
            ->call('authenticate');
    }

    $this->travel(2)->minutes();

    livewire(SysadminLogin::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    $this->assertGuest('sysadmin');
});
