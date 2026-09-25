<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Http\Controllers\Mail\UnsubscribeController;
use App\Models\User;
use Illuminate\Support\Facades\URL;

mutates(UnsubscribeController::class);

function digestUnsubscribeUrl(User $user): string
{
    return URL::signedRoute('mail.unsubscribe', ['user' => $user->id, 'type' => NotificationType::TaskDigest->value]);
}

it('rejects an unsigned url', function (): void {
    $user = User::factory()->create();

    $this->post(route('mail.unsubscribe.store', ['user' => $user->id, 'type' => 'task_digest']))->assertForbidden();

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeTrue();
});

it('rejects a signature copied onto another user', function (): void {
    $user = User::factory()->create();
    $victim = User::factory()->create();

    $tampered = str_replace("/{$user->id}/", "/{$victim->id}/", digestUnsubscribeUrl($user));

    $this->post($tampered)->assertForbidden();

    expect($victim->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeTrue();
});

it('turns the digest off on a one-click post', function (): void {
    $user = User::factory()->create();

    $this->post(digestUnsubscribeUrl($user), ['List-Unsubscribe' => 'One-Click'])->assertOk();

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('returns the confirmation page when the post is not a one-click unsubscribe', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->post(digestUnsubscribeUrl($user))
        ->assertOk()
        ->assertSee(__('mail.unsubscribe.done_heading'))
        ->assertSee(__('mail.unsubscribe.done_body', ['email' => 'ada@example.com']));
});

it('is idempotent', function (): void {
    $user = User::factory()->create();

    $this->post(digestUnsubscribeUrl($user))->assertOk();
    $this->post(digestUnsubscribeUrl($user))->assertOk();

    expect($user->fresh()->wantsNotification(NotificationType::TaskDigest, NotificationChannel::Email))->toBeFalse();
});

it('shows a confirmation page on get', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->get(digestUnsubscribeUrl($user))
        ->assertOk()
        ->assertSee(__('mail.unsubscribe.heading'))
        ->assertSee('ada@example.com');
});

it('rejects a type that has no email unsubscribe', function (): void {
    $user = User::factory()->create();

    $this->get(URL::signedRoute('mail.unsubscribe', ['user' => $user->id, 'type' => 'task_assigned']))->assertNotFound();
});

it('sends a no-referrer policy so the signed url never leaks through outbound links', function (): void {
    $user = User::factory()->create();

    $this->get(digestUnsubscribeUrl($user))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');
});
