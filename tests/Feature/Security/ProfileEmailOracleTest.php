<?php

declare(strict_types=1);

use App\Livewire\App\Profile\UpdateProfileInformation as UpdateProfileInformationComponent;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create([
        'email' => 'owner@example.com',
        'email_verified_at' => now(),
    ]);
});

function probeProfileEmail(object $test, User $user, string $email): TestResponse
{
    return $test->actingAs($user)->putJson(route('user-profile-information.update'), [
        'name' => $user->name,
        'email' => $email,
    ]);
}

test('a user cannot probe candidate emails past the per-minute cap', function () {
    foreach (range(1, 20) as $attempt) {
        probeProfileEmail($this, $this->user, "candidate-{$attempt}@example.com")->assertStatus(423);
    }

    probeProfileEmail($this, $this->user, 'candidate-21@example.com')->assertTooManyRequests();
});

test('registered and unregistered emails are throttled together', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 10) as $attempt) {
        probeProfileEmail($this, $this->user, 'victim@example.com')->assertUnprocessable();
        probeProfileEmail($this, $this->user, "free-{$attempt}@example.com")->assertStatus(423);
    }

    probeProfileEmail($this, $this->user, 'victim@example.com')->assertTooManyRequests();
});

test('the daily cap holds after the minute window resets', function () {
    foreach (range(1, 15) as $batch) {
        foreach (range(1, 20) as $attempt) {
            probeProfileEmail($this, $this->user, "batch-{$batch}-{$attempt}@example.com");
        }

        $this->travel(61)->seconds();
    }

    probeProfileEmail($this, $this->user, 'one-more@example.com')->assertTooManyRequests();
});

test('one user hitting the cap does not throttle another user', function () {
    foreach (range(1, 21) as $attempt) {
        probeProfileEmail($this, $this->user, "candidate-{$attempt}@example.com");
    }

    $other = User::factory()->withWorkspace()->create(['email' => 'other@example.com']);

    probeProfileEmail($this, $other, 'candidate-x@example.com')->assertStatus(423);
});

test('a legitimate profile update is not throttled', function () {
    $this->actingAs($this->user)
        ->putJson(route('user-profile-information.update'), [
            'name' => 'Renamed Over Http',
            'email' => 'owner@example.com',
            'timezone' => 'Europe/Berlin',
        ])
        ->assertSuccessful();

    expect($this->user->fresh())
        ->name->toBe('Renamed Over Http')
        ->timezone->toBe('Europe/Berlin');
});

function probeConfirmEmailChange(User $user, string $email): Testable
{
    return Livewire::actingAs($user)
        ->test(UpdateProfileInformationComponent::class)
        ->set('data.email', $email)
        ->call('mountAction', 'confirmEmailChange');
}

function probeUpdateProfile(User $user, string $email): Testable
{
    RateLimiter::clear('livewire-rate-limiter:'.sha1(UpdateProfileInformationComponent::class.'|updateProfile|'.request()->ip()));

    return Livewire::actingAs($user)
        ->test(UpdateProfileInformationComponent::class)
        ->set('data.email', $email)
        ->call('updateProfile');
}

test('mounting the email change action cannot probe emails past the cap', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 20) as $attempt) {
        probeConfirmEmailChange($this->user, 'victim@example.com')->assertHasErrors(['data.email']);
    }

    probeConfirmEmailChange($this->user, 'victim@example.com')
        ->assertHasNoErrors()
        ->assertNotified('Too Many Requests');
});

test('saving the profile form cannot probe emails past the cap regardless of ip', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 20) as $attempt) {
        probeUpdateProfile($this->user, 'victim@example.com')->assertHasErrors(['data.email']);
    }

    probeUpdateProfile($this->user, 'victim@example.com')
        ->assertHasNoErrors()
        ->assertNotified('Too Many Requests');
});

test('the route and the livewire form draw on one budget', function () {
    User::factory()->create(['email' => 'victim@example.com']);

    foreach (range(1, 10) as $attempt) {
        probeProfileEmail($this, $this->user, 'victim@example.com')->assertUnprocessable();
    }

    foreach (range(1, 10) as $attempt) {
        probeConfirmEmailChange($this->user, 'victim@example.com')->assertHasErrors(['data.email']);
    }

    probeConfirmEmailChange($this->user, 'victim@example.com')->assertNotified('Too Many Requests');
});

test('a legitimate email change through the livewire form still works', function () {
    probeUpdateProfile($this->user, 'fresh@example.com')->assertHasNoErrors();
    probeConfirmEmailChange($this->user, 'fresh@example.com')
        ->assertHasNoErrors()
        ->assertActionMounted('confirmEmailChange');
});

test('saving a new name through the livewire form is not counted as a probe', function () {
    foreach (range(1, 25) as $attempt) {
        RateLimiter::clear('livewire-rate-limiter:'.sha1(UpdateProfileInformationComponent::class.'|updateProfile|'.request()->ip()));

        Livewire::actingAs($this->user)
            ->test(UpdateProfileInformationComponent::class)
            ->set('data.name', "Name {$attempt}")
            ->call('updateProfile')
            ->assertHasNoErrors()
            ->assertNotNotified('Too Many Requests');
    }

    expect($this->user->fresh()->name)->toBe('Name 25');
});
