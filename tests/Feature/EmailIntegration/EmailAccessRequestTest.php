<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

mutates(ApproveEmailAccessRequestAction::class, CancelEmailAccessRequestAction::class, DenyEmailAccessRequestAction::class);

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

describe('ApproveEmailAccessRequestAction', function (): void {
    it('shares the email with the requester at the requested tier', function (): void {
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

    it('updates request status to APPROVED', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::SUBJECT)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('sends a notification to the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does nothing when request is already approved', function (): void {
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

    it('does nothing when request is already denied', function (): void {
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
});

describe('DenyEmailAccessRequestAction', function (): void {
    it('updates request status to DENIED', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('sends a notification to the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does not create a share', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request);

        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('does nothing when request is already approved', function (): void {
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

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request);

        Notification::assertNothingSent();
    });
});

describe('CancelEmailAccessRequestAction', function (): void {
    it('deletes a pending request', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        app(CancelEmailAccessRequestAction::class)->execute($request);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeFalse();
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        app(CancelEmailAccessRequestAction::class)->execute($request);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        app(CancelEmailAccessRequestAction::class)->execute($request);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });
});
