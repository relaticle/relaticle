<?php

declare(strict_types=1);

use App\Actions\Auth\RequestEmailChallenge;
use App\Enums\EmailChallengePurpose;
use App\Http\Controllers\Auth\EmailChallengeController;
use App\Http\Controllers\Auth\ResendEmailChallengeController;
use App\Http\Controllers\Auth\VerifyEmailChallengeController;
use App\Jobs\Email\SendEmailChallenge;
use App\Models\EmailChallenge;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository as CacheStoreRepository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

mutates(RequestEmailChallenge::class, EmailChallengeController::class, ResendEmailChallengeController::class, VerifyEmailChallengeController::class);

test('requesting verification queues one code without changing account state', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
    ])->assertAccepted();

    expect($user->refresh()->email_verified_at)->toBeNull();
    Queue::assertPushed(SendEmailChallenge::class, 1);
});

test('the response carries only the challenge id, expiry, and resend availability', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
    ])->assertAccepted();

    expect($response->json())->toHaveKeys(['challenge_id', 'expires_at', 'resend_available_at'])
        ->and(array_keys($response->json()))->toHaveCount(3);
});

test('a malformed purpose is rejected before any code is issued', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'not-a-real-purpose',
    ])->assertUnprocessable()->assertJsonValidationErrors('purpose');

    Queue::assertNotPushed(SendEmailChallenge::class);
    expect(EmailChallenge::query()->count())->toBe(0);
});

test('an array-typed purpose is rejected with a validation error, not a server error', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => ['verify_email'],
    ])->assertUnprocessable()->assertJsonValidationErrors('purpose');

    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('an array-typed email is rejected with a validation error, not a server error', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
        'email' => ['attacker@example.com'],
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('an array-typed operation id is rejected with a validation error, not a server error', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
        'operation_id' => ['x'],
    ])->assertUnprocessable()->assertJsonValidationErrors('operation_id');

    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('every purpose besides verify_email stays refused over raw HTTP', function (string $purpose): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => $purpose,
        'email' => 'guest@example.com',
    ])->assertUnprocessable()->assertJsonValidationErrors('purpose');

    Queue::assertNotPushed(SendEmailChallenge::class);
    expect(EmailChallenge::query()->count())->toBe(0);
})->with(['signup', 'sign_in', 'confirm_identity', 'change_email', 'enable_email_sign_in']);

test('verify_email with a submitted operation id is refused, not silently ignored', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
        'operation_id' => (string) Str::ulid(),
    ])->assertUnprocessable()->assertJsonValidationErrors('purpose');

    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('a guest cannot request verification without an authenticated session', function (): void {
    Queue::fake([SendEmailChallenge::class]);

    $this->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
    ])->assertUnprocessable();

    Queue::assertNotPushed(SendEmailChallenge::class);
    expect(EmailChallenge::query()->count())->toBe(0);
});

test('a submitted email is ignored for authenticated verification', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create(['email' => 'real@example.com']);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
        'purpose' => 'verify_email',
        'email' => 'attacker@example.com',
    ])->assertAccepted();

    $challenge = EmailChallenge::query()->sole();
    expect($challenge->email)->toBe('real@example.com');
});

test('the issued challenge binds the state of the account it verifies', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    $challenge = EmailChallenge::query()->sole();

    expect($challenge->state_fingerprint)->not->toBeNull()
        ->and($challenge->state_fingerprint)->toBe(EmailChallenge::stateFingerprint($user));
});

test('a second request inside the cooldown window is throttled', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purpose');

    Queue::assertPushed(SendEmailChallenge::class, 1);
});

test('a fourth send inside fifteen minutes is blocked by the window limit', function (): void {
    $this->freezeTime();
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
            ->assertAccepted();
        $this->travel(61)->seconds();
    }

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purpose');

    Queue::assertPushed(SendEmailChallenge::class, 3);
});

test('twenty requests in an hour exhaust the shared per-IP limit across purposes', function (): void {
    $this->freezeTime();
    Queue::fake([SendEmailChallenge::class]);

    foreach (range(1, 20) as $attempt) {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
            ->assertAccepted();
    }

    $anotherUser = User::factory()->create();
    $this->actingAs($anotherUser)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    Queue::assertPushed(SendEmailChallenge::class, 20);
});

test('a purpose refused before the limiters run does not consume the shared IP budget', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    foreach (range(1, 25) as $attempt) {
        $this->actingAs($user)->postJson(route('auth.email-challenges.store'), [
            'purpose' => 'signup',
            'email' => 'guest@example.com',
        ])->assertUnprocessable();
    }

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    Queue::assertPushed(SendEmailChallenge::class, 1);
});

test('a resend after the cooldown reuses the same flow and invalidates the prior generation', function (): void {
    $this->freezeTime();
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    $this->travel(61)->seconds();

    $second = $this->actingAs($user)->postJson(route('auth.email-challenges.resend'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    expect($second->json('challenge_id'))->toBe($first->json('challenge_id'));

    $generations = EmailChallenge::query()
        ->where('flow_id', $first->json('challenge_id'))
        ->orderBy('generation')
        ->get();

    expect($generations)->toHaveCount(2)
        ->and($generations[0]->invalidated_at)->not->toBeNull()
        ->and($generations[0]->invalidation_reason)->toBe('superseded')
        ->and($generations[1]->generation)->toBe(2)
        ->and($generations[1]->invalidated_at)->toBeNull()
        ->and($generations[1]->failed_attempts)->toBe(0);

    Queue::assertPushed(SendEmailChallenge::class, 2);
});

test('refreshing the same session before the cooldown lifts still gets throttled, not a second flow', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    $this->actingAs($user)->postJson(route('auth.email-challenges.resend'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    expect(EmailChallenge::query()->where('flow_id', $first->json('challenge_id'))->count())->toBe(1);
    Queue::assertPushed(SendEmailChallenge::class, 1);
});

test('a second browser session for the same account never supersedes the first session\'s live code', function (): void {
    $this->freezeTime();
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $desktop = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    $this->travel(61)->seconds();

    session()->flush();

    $phone = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    expect($phone->json('challenge_id'))->not->toBe($desktop->json('challenge_id'));

    $desktopGeneration = EmailChallenge::query()->findOrFail($desktop->json('challenge_id'));

    expect($desktopGeneration->invalidated_at)->toBeNull()
        ->and($desktopGeneration->consumed_at)->toBeNull()
        ->and(EmailChallenge::query()->count())->toBe(2);
});

test('the flow secret held in session is required to resend; a wrong secret starts a fresh flow instead', function (): void {
    $this->freezeTime();
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $first = $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    session()->put('auth.email_challenge.flow.verify_email', [
        'flow_id' => $first->json('challenge_id'),
        'secret' => 'a-completely-wrong-secret',
        'target' => $user->email,
    ]);

    $this->travel(61)->seconds();

    $second = $this->actingAs($user)->postJson(route('auth.email-challenges.resend'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    expect($second->json('challenge_id'))->not->toBe($first->json('challenge_id'));

    $firstGeneration = EmailChallenge::query()->findOrFail($first->json('challenge_id'));
    expect($firstGeneration->invalidated_at)->toBeNull();
});

test('issuance fails closed when only the IP limiter is unavailable', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $partiallyBrokenCache = new class(new ArrayStore) extends CacheStoreRepository
    {
        public function increment($key, $value = 1): mixed
        {
            throw_unless(str_contains((string) $key, '@'), RuntimeException::class, 'cache unavailable');

            return parent::increment($key, $value);
        }

        public function add($key, $value, $ttl = null): mixed
        {
            throw_unless(str_contains((string) $key, '@'), RuntimeException::class, 'cache unavailable');

            return parent::add($key, $value, $ttl);
        }
    };

    app()->instance(CacheRateLimiter::class, new CacheRateLimiter($partiallyBrokenCache));
    Facade::clearResolvedInstance(CacheRateLimiter::class);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    expect(EmailChallenge::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('issuance fails closed when only the email/purpose limiter is unavailable', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $partiallyBrokenCache = new class(new ArrayStore) extends CacheStoreRepository
    {
        public function increment($key, $value = 1): mixed
        {
            throw_if(str_contains((string) $key, '@'), RuntimeException::class, 'cache unavailable');

            return parent::increment($key, $value);
        }

        public function add($key, $value, $ttl = null): mixed
        {
            throw_if(str_contains((string) $key, '@'), RuntimeException::class, 'cache unavailable');

            return parent::add($key, $value, $ttl);
        }
    };

    app()->instance(CacheRateLimiter::class, new CacheRateLimiter($partiallyBrokenCache));
    Facade::clearResolvedInstance(CacheRateLimiter::class);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    expect(EmailChallenge::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('every limiter hit is a single atomic call, not a separate check then write', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    $countingCache = new class(new ArrayStore) extends CacheStoreRepository
    {
        public int $getCalls = 0;

        public function get($key, $default = null): mixed
        {
            $this->getCalls++;

            return parent::get($key, $default);
        }
    };

    app()->instance(CacheRateLimiter::class, new CacheRateLimiter($countingCache));
    Facade::clearResolvedInstance(CacheRateLimiter::class);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertAccepted();

    expect($countingCache->getCalls)->toBe(6);
});

test('issuance fails closed when the application key is missing', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    app('encrypter');
    config(['app.key' => '']);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    expect(EmailChallenge::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('issuance fails closed when the application key is not valid base64', function (): void {
    Queue::fake([SendEmailChallenge::class]);
    $user = User::factory()->create();

    app('encrypter');
    config(['app.key' => 'base64:not-valid-base64!!']);

    $this->actingAs($user)->postJson(route('auth.email-challenges.store'), ['purpose' => 'verify_email'])
        ->assertUnprocessable();

    expect(EmailChallenge::query()->count())->toBe(0);
    Queue::assertNotPushed(SendEmailChallenge::class);
});

test('challenge digests cannot be derived without an application key', function (): void {
    config(['app.key' => '']);

    expect(fn () => EmailChallenge::hashCode('flow-id', EmailChallengePurpose::VERIFY_EMAIL, '123456'))
        ->toThrow(RuntimeException::class);
});

test('challenge digests cannot be derived from an invalid application key', function (): void {
    config(['app.key' => 'base64:not-valid-base64!!']);

    expect(fn () => EmailChallenge::hashFlowSecret('some-flow-secret'))
        ->toThrow(RuntimeException::class);
});

test('the verify route refuses deterministically instead of 404ing or crashing', function (): void {
    $this->postJson(route('auth.email-challenges.verify'), [
        'challenge_id' => (string) Str::ulid(),
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
});
