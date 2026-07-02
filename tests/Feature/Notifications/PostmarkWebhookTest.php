<?php

declare(strict_types=1);

use App\Enums\Notifications\DigestCadence;
use App\Models\User;

beforeEach(function (): void {
    config(['services.postmark.webhook_secret' => 'test-secret']);
});

it('switches the digest off when Postmark reports a suppression', function (): void {
    $user = User::factory()->create(['email' => 'sam@example.com']);

    $this->postJson('/webhooks/postmark/test-secret', [
        'RecordType' => 'SubscriptionChange',
        'Recipient' => 'sam@example.com',
        'SuppressSending' => true,
        'SuppressionReason' => 'ManualSuppression',
    ])->assertOk();

    expect($user->fresh()->notificationPreferences()->digestCadence)->toBe(DigestCadence::Off);
});

it('rejects an invalid secret', function (): void {
    $this->postJson('/webhooks/postmark/wrong', [
        'RecordType' => 'SubscriptionChange',
        'Recipient' => 'sam@example.com',
        'SuppressSending' => true,
    ])->assertNotFound();
});

it('ignores an unknown recipient', function (): void {
    $this->postJson('/webhooks/postmark/test-secret', [
        'RecordType' => 'SubscriptionChange',
        'Recipient' => 'nobody@example.com',
        'SuppressSending' => true,
    ])->assertOk();
});
