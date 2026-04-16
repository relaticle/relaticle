<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

mutates(ApproveEmailAccessRequestAction::class, DenyEmailAccessRequestAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->actingAs($this->owner);
    $this->team = $this->owner->currentTeam;
    Filament::setTenant($this->team);

    $this->requester = User::factory()->create(['current_team_id' => $this->team->id]);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Confidential Thread',
    ]);
});

// ApproveEmailAccessRequestAction tests

test('ApproveEmailAccessRequestAction shares the email with the requester at the requested tier', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(ApproveEmailAccessRequestAction::class)->execute($request);

    $this->assertDatabaseHas('email_shares', [
        'email_id' => $this->email->getKey(),
        'shared_with' => $this->requester->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
});

test('ApproveEmailAccessRequestAction updates request status to APPROVED', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::SUBJECT)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(ApproveEmailAccessRequestAction::class)->execute($request);

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
});

test('ApproveEmailAccessRequestAction sends a notification to the requester', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(ApproveEmailAccessRequestAction::class)->execute($request);

    Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
});

test('ApproveEmailAccessRequestAction does nothing when request is already approved', function (): void {
    $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(ApproveEmailAccessRequestAction::class)->execute($request);

    Notification::assertNothingSent();
    expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
});

test('ApproveEmailAccessRequestAction does nothing when request is already denied', function (): void {
    $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(ApproveEmailAccessRequestAction::class)->execute($request);

    Notification::assertNothingSent();
    expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
});

// DenyEmailAccessRequestAction tests

test('DenyEmailAccessRequestAction updates request status to DENIED', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(DenyEmailAccessRequestAction::class)->execute($request);

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
});

test('DenyEmailAccessRequestAction sends a notification to the requester', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(DenyEmailAccessRequestAction::class)->execute($request);

    Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
});

test('DenyEmailAccessRequestAction does not create a share', function (): void {
    $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(DenyEmailAccessRequestAction::class)->execute($request);

    expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
});

test('DenyEmailAccessRequestAction does nothing when request is already approved', function (): void {
    $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(DenyEmailAccessRequestAction::class)->execute($request);

    Notification::assertNothingSent();
    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
});

test('DenyEmailAccessRequestAction does nothing when request is already denied', function (): void {
    $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => $this->requester->id,
        'owner_id' => $this->owner->id,
        'email_id' => $this->email->getKey(),
    ]);

    Notification::fake();

    app(DenyEmailAccessRequestAction::class)->execute($request);

    Notification::assertNothingSent();
});
