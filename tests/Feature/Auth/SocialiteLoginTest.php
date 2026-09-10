<?php

declare(strict_types=1);

use App\Actions\Auth\LinkSocialAccount;
use App\Enums\AuthMethod;
use App\Enums\SocialiteProvider;
use App\Filament\Pages\Dashboard;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\LinkSocialAccountCallbackController;
use App\Http\Controllers\Auth\LinkSocialAccountRedirectController;
use App\Http\Controllers\Auth\RedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\IdentityConfirmation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

mutates(
    CallbackController::class,
    RedirectController::class,
    LinkSocialAccount::class,
    LinkSocialAccountRedirectController::class,
    LinkSocialAccountCallbackController::class,
);

function makeSocialiteUser(string $id, string $name, string $email): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

test('redirect to socialite provider', function () {
    Socialite::fake(SocialiteProvider::GOOGLE->value);

    $response = $this->get(route('auth.socialite.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect();
});

test('callback from socialite provider creates new user when user does not exist', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Test User', 'test@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $this->assertDatabaseHas('user_social_accounts', [
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    $this->assertAuthenticated();

    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider logs in existing user when social account exists', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Existing User', 'existing@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(Dashboard::getUrl(['tenant' => $user->currentTeam]));
});

test('linked Google login cannot bypass enrolled MFA', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'existing-google-id',
    ]);

    Socialite::fake(SocialiteProvider::GOOGLE->value, makeSocialiteUser('existing-google-id', 'Maya', $user->email));

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
});

test('unlinking a social account while its MFA challenge is pending cannot complete authentication', function (): void {
    $user = User::factory()->withConfirmedMfa()->create();
    $account = UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
    ]);

    AuthenticationSession::begin($user, AuthMethod::GOOGLE, (string) $account->getKey(), true);

    $account->delete();

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'recovery-code-one',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest('web');
});

test('callback rejects an external destination for an account without a workspace', function (): void {
    $user = User::factory()->create(['email' => 'no-workspace@example.com']);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'No Workspace', 'no-workspace@example.com'),
    );

    session()->put('url.intended', 'https://untrusted.example/collect');

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(url()->getAppUrl());
});

test('matching provider email cannot sign in to an unlinked account', function (): void {
    $user = User::factory()->create(['email' => 'maya@example.com']);
    Socialite::fake('google', makeSocialiteUser('new-provider-id', 'Maya', $user->email));

    $this->get(route('auth.socialite.callback', ['provider' => 'google', 'code' => 'accepted']))
        ->assertRedirect();

    $this->assertGuest('web');
    expect($user->socialAccounts()->exists())->toBeFalse();
});

test('a mixed-case provider email match still cannot sign in to an unlinked account', function (): void {
    $user = User::factory()->withTeam()->create(['email' => 'case-link-'.uniqid().'@example.com']);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Existing User', mb_strtoupper($user->email)),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect();

    $this->assertGuest('web');
    expect(User::where('email', 'ilike', $user->email)->count())->toBe(1)
        ->and($user->socialAccounts()->where('provider_name', 'google')->exists())->toBeFalse();
});

test('a matching-email link suggestion survives a subsequent password login', function (): void {
    $user = User::factory()->withTeam()->create(['email' => 'maya@example.com']);
    Socialite::fake('google', makeSocialiteUser('new-provider-id', 'Maya', $user->email));

    $this->get(route('auth.socialite.callback', ['provider' => 'google', 'code' => 'accepted']));

    expect(AuthenticationSession::linkSuggestion())->not->toBe([]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(AuthenticationSession::linkSuggestion())->not->toBe([]);
});

test('an existing linked account still signs in when the provider now reports a different email', function (): void {
    $user = User::factory()->withTeam()->create(['email' => 'old-address@example.com']);
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'stable-google-id',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('stable-google-id', 'Existing User', 'new-address@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']))
        ->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('callback from socialite provider handles error gracefully', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        fn () => throw new Exception('Socialite error'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
});

test('callback from socialite provider handles missing code parameter', function () {
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $response->assertSessionHas('errors');

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Authorization was cancelled or failed. Please try again.');
});

test('callback from socialite provider rejects a disposable email address', function () {
    Exceptions::fake();

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Burner User', 'burner@mailinator.com'),
    );

    $response = $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login' => __('validation.indisposable')]);

    $this->assertDatabaseMissing('users', ['email' => 'burner@mailinator.com']);
    $this->assertGuest();

    Exceptions::assertNothingReported();
});

/**
 * The signup event was flagged only by the registration form, so every OAuth
 * sign-up reached the panel without one. That made the number wrong rather
 * than merely incomplete, and it is the undercount that made the Fathom
 * signup series disagree with the users table.
 */
test('callback flags the signup event when the OAuth user is new', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Fresh User', 'fresh@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    expect(session()->get('fathom.track_signup'))->toBeTrue();
});

test('callback does not flag the signup event when the OAuth user already exists', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'returning@example.com',
        'name' => 'Returning User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->getKey(),
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '55555',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('55555', 'Returning User', 'returning@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    expect(session()->has('fathom.track_signup'))->toBeFalse();
});

test('redirect to microsoft provider', function () {
    $this->get(route('auth.socialite.redirect', ['provider' => SocialiteProvider::MICROSOFT->value]))
        ->assertRedirectContains('login.microsoftonline.com/common/oauth2/v2.0/authorize')
        ->assertRedirectContains('redirect_uri='.urlencode(url('/auth/callback/microsoft')));
});

test('microsoft callback creates a user', function () {
    Socialite::fake(
        SocialiteProvider::MICROSOFT->value,
        makeSocialiteUser('ms-1', 'MS User', 'ms@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => 'microsoft', 'code' => 'test-code']));

    $this->assertDatabaseHas('user_social_accounts', ['provider_name' => 'microsoft', 'provider_id' => 'ms-1']);
    $this->assertAuthenticated();
});

test('github redirect route is gone', function () {
    $this->get('/auth/redirect/github')->assertNotFound();
});

test('github callback route is gone', function () {
    $this->get('/auth/callback/github?code=x')->assertNotFound();
});

test('callback rejects unknown providers', function () {
    $this->get('/auth/callback/bitbucket?code=x')->assertNotFound();
});

test('link redirect requires a fresh identity confirmation', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirect(route('password.confirm'));

    expect(AuthenticationSession::pendingOperation())->toBe([]);
});

test('link redirect to google for a confirmed user requests account selection', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirectContains('prompt=select_account')
        ->assertRedirectContains('redirect_uri='.urlencode(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value])));
});

test('link redirect to microsoft for a confirmed user forces re-authentication', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::MICROSOFT->value]))
        ->assertRedirectContains('prompt=login')
        ->assertRedirectContains('redirect_uri='.urlencode(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::MICROSOFT->value])));
});

test('link callback creates a new social account for a confirmed user', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('new-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    $this->assertDatabaseHas('user_social_accounts', [
        'user_id' => $user->getKey(),
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'new-google-id',
    ]);
});

test('hitting the link callback directly without first visiting the link redirect cannot link a provider', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('direct-hit-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('password.confirm'));

    $this->assertDatabaseMissing('user_social_accounts', ['provider_id' => 'direct-hit-google-id']);
});

test('link callback handles a cancelled authorization', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value]))
        ->assertRedirect(route('password.confirm'));

    $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id]);
});

test('link callback treats an invalid oauth state as a failed link attempt', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        fn () => throw new InvalidStateException,
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('password.confirm'));

    $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id]);
});

test('link callback rejects an expired operation grant', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $this->travel(16)->minutes();

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('late-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('password.confirm'));

    $this->assertDatabaseMissing('user_social_accounts', ['provider_id' => 'late-google-id']);
});

test('link callback refuses a grant overwritten by another operation before the round trip returned', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    AuthenticationSession::startOperation($user, 'set_password', null);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('clobbered-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    $this->assertDatabaseMissing('user_social_accounts', ['provider_id' => 'clobbered-google-id']);
});

test('link callback rejects a grant minted before the password was rotated', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $user->forceFill(['password' => Hash::make('a-brand-new-password')])->save();

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('post-rotation-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('password.confirm'));

    $this->assertDatabaseMissing('user_social_accounts', ['provider_id' => 'post-rotation-google-id']);
});

test('linking a provider identity already linked to another account does not reassign it', function (): void {
    $owner = User::factory()->withTeam()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $owner->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'contested-google-id',
    ]);

    $challenger = User::factory()->withTeam()->create();
    $this->actingAs($challenger);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('contested-google-id', 'Challenger', $challenger->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    expect(UserSocialAccount::where('provider_id', 'contested-google-id')->count())->toBe(1);
    $this->assertDatabaseHas('user_social_accounts', [
        'user_id' => $owner->id,
        'provider_id' => 'contested-google-id',
    ]);
});

test('a link rejected by the unique index after its pre-check passes reports it as already linked', function (): void {
    $racer = User::factory()->withTeam()->create();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    UserSocialAccount::creating(function (UserSocialAccount $account) use ($racer): void {
        DB::table('user_social_accounts')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $racer->id,
            'provider_name' => $account->provider_name,
            'provider_id' => $account->provider_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('raced-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect(route('password.confirm'));

    expect(UserSocialAccount::where('provider_id', 'raced-google-id')->count())->toBe(0);
    $this->assertDatabaseMissing('user_social_accounts', ['user_id' => $user->id]);
});

test('linking a provider the user already has one linked for reports already linked without duplicating the row', function (): void {
    $user = User::factory()->withTeam()->create();
    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => 'already-mine',
    ]);
    $this->actingAs($user);
    IdentityConfirmation::markConfirmed();

    $this->get(route('auth.socialite.link.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('a-different-google-id', 'Existing User', $user->email),
    );

    $this->get(route('auth.socialite.link.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'accepted']))
        ->assertRedirect();

    expect($user->socialAccounts()->where('provider_name', SocialiteProvider::GOOGLE->value)->count())->toBe(1);
});
