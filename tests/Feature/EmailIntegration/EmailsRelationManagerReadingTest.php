<?php

declare(strict_types=1);

use App\Filament\RelationManagers\BaseEmailsRelationManager;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

mutates(BaseEmailsRelationManager::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

describe('requestAccess table action', function (): void {
    it('creates an EmailAccessRequest and notifies the owner', function (): void {
        $this->actingAs($this->viewer);

        Notification::fake();

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('requestAccess', $email, data: [
                'tier_requested' => EmailPrivacyTier::FULL->value,
            ])
            ->assertNotified('Access request sent.');

        expect(
            EmailAccessRequest::where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->where('owner_id', $this->owner->id)
                ->where('status', 'pending')
                ->exists()
        )->toBeTrue();

        Notification::assertSentTo($this->owner, EmailAccessRequestedNotification::class);
    });

    it('shows a warning notification when a pending request already exists', function (): void {
        $this->actingAs($this->viewer);

        Notification::fake();

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        EmailAccessRequest::factory()->pending()->forTier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'requester_id' => $this->viewer->id,
            'owner_id' => $this->owner->id,
        ]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('requestAccess', $email, data: [
                'tier_requested' => EmailPrivacyTier::FULL->value,
            ])
            ->assertNotified('You already have a pending request for this email.');

        expect(
            EmailAccessRequest::where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->count()
        )->toBe(1);
    });

    it('is hidden when the authenticated user is the email owner', function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('requestAccess', $email);
    });

    it('is hidden when the viewer already has full body access via a share', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'shared_by' => $this->owner->id,
            'shared_with' => $this->viewer->id,
        ]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('requestAccess', $email);
    });
});

describe('manageSharing table action', function (): void {
    it('updates the email privacy_tier', function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ])
            ->assertNotified('Sharing settings saved.');

        expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('creates EmailShare rows for each specified teammate', function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    [
                        'shared_with' => $this->viewer->id,
                        'tier' => EmailPrivacyTier::SUBJECT->value,
                    ],
                ],
            ])
            ->assertNotified('Sharing settings saved.');

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    });

    it("clears the owner's previous shares when saved with an empty shares list", function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        EmailShare::factory()->create([
            'email_id' => $email->getKey(),
            'shared_by' => $this->owner->id,
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('manageSharing', $email, data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [],
            ])
            ->assertNotified('Sharing settings saved.');

        $this->assertDatabaseMissing('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
        ]);
    });

    it('is hidden for non-owners', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('manageSharing', $email);
    });
});

describe('shareAllOnRecord header action', function (): void {
    it('updates privacy tier on all owner emails linked to the record', function (): void {
        $emailA = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $emailB = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($emailA->getKey());
        $this->person->emails()->attach($emailB->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('shareAllOnRecord', data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ])
            ->assertNotified('Sharing settings saved for all your emails on this record.');

        expect($emailA->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
            ->and($emailB->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('creates EmailShare rows for each email on the record per specified teammate', function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callTableAction('shareAllOnRecord', data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    [
                        'shared_with' => $this->viewer->id,
                        'tier' => EmailPrivacyTier::SUBJECT->value,
                    ],
                ],
            ])
            ->assertNotified('Sharing settings saved for all your emails on this record.');

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    });

    it('is hidden when the authenticated user has no emails linked to the record', function (): void {
        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableActionHidden('shareAllOnRecord');
    });
});

describe('subject column privacy enforcement', function (): void {
    it('shows (subject hidden) when the viewer cannot view the subject', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Secret Subject',
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableColumnStateSet('subject', '(subject hidden)', $email);
    });

    it('shows the real subject when the viewer can view the subject', function (): void {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Real Subject',
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertTableColumnStateSet('subject', 'Real Subject', $email);
    });
});
