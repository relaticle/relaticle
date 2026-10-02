<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(BaseEmailsRelationManager::class, EmailVisibilityService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->viewer, ['role' => 'member']);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->owner->id,
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

describe('requestAccess action', function (): void {
    it('creates an EmailAccessRequest and notifies the owner', function (): void {
        $this->actingAs($this->viewer);

        Notification::fake();

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('requestAccess', data: [
                'tier_requested' => EmailPrivacyTier::FULL->value,
            ], arguments: ['emailId' => $email->getKey()])
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

    it('is hidden when a pending request already exists', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->assertActionHidden('requestAccess', ['emailId' => $email->getKey()]);

        expect(
            EmailAccessRequest::where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->count()
        )->toBe(1);
    });

    it('is hidden when the authenticated user is the email owner', function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertActionHidden('requestAccess', ['emailId' => $email->getKey()]);
    });

    it('is hidden when the viewer already has full body access via a share', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->assertActionHidden('requestAccess', ['emailId' => $email->getKey()]);
    });
});

describe('manageSharing action', function (): void {
    it('updates the email privacy_tier', function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('manageSharing', data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ], arguments: ['emailId' => $email->getKey()])
            ->assertNotified('Sharing settings saved.');

        expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('creates EmailShare rows for each specified teammate', function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('manageSharing', data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    [
                        'tier' => EmailPrivacyTier::SUBJECT->value,
                        'shared_with' => [$this->viewer->id],
                    ],
                ],
            ], arguments: ['emailId' => $email->getKey()])
            ->assertNotified('Sharing settings saved.');

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
            'tier' => EmailPrivacyTier::SUBJECT->value,
        ]);
    });

    it('creates EmailShare rows for multiple teammates selected in one tier row', function (): void {
        $secondViewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->workspace->users()->attach($secondViewer, ['role' => 'member']);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('manageSharing', data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [
                    [
                        'tier' => EmailPrivacyTier::SUBJECT->value,
                        'shared_with' => [
                            $this->viewer->id,
                            $secondViewer->id,
                        ],
                    ],
                ],
            ], arguments: ['emailId' => $email->getKey()])
            ->assertNotified('Sharing settings saved.');

        foreach ([$this->viewer, $secondViewer] as $viewer) {
            $this->assertDatabaseHas('email_shares', [
                'email_id' => $email->getKey(),
                'shared_with' => $viewer->id,
                'tier' => EmailPrivacyTier::SUBJECT->value,
            ]);
        }
    });

    it("clears the owner's previous shares when saved with an empty shares list", function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->callAction('manageSharing', data: [
                'privacy_tier' => EmailPrivacyTier::METADATA_ONLY->value,
                'shares' => [],
            ], arguments: ['emailId' => $email->getKey()])
            ->assertNotified('Sharing settings saved.');

        $this->assertDatabaseMissing('email_shares', [
            'email_id' => $email->getKey(),
            'shared_with' => $this->viewer->id,
        ]);
    });

    it('rejects sharing changes from non-owners', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->callAction('manageSharing', data: [
                'privacy_tier' => EmailPrivacyTier::FULL->value,
                'shares' => [],
            ], arguments: ['emailId' => $email->getKey()])
            ->assertForbidden();

        expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
    });
});

describe('reader overlay', function (): void {
    it('stays closed when the viewer cannot read the body', function (EmailPrivacyTier $tier): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => $tier,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertDontSeeHtml("selectEmail('{$email->getKey()}')")
            ->assertActionVisible('requestAccess', ['emailId' => $email->getKey()])
            ->call('selectEmail', $email->getKey())
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('fi-email-reader-panel');
    })->with([
        EmailPrivacyTier::METADATA_ONLY,
        EmailPrivacyTier::SUBJECT,
    ]);
});

describe('access request approve and deny from the reader overlay', function (): void {
    it('approves a pending request from the relation manager overlay', function (): void {
        $email = Email::factory()->private()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Deal terms',
        ]);

        $this->person->emails()->attach($email->getKey());

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'requester_id' => $this->viewer->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $email->getKey())
            ->assertSet('selectedEmailId', $email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->assertSee($this->viewer->name)
            ->assertSee(__('filament/pages/email-inbox.pending_access.approve'))
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.approved'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('denies a pending request from the relation manager overlay', function (): void {
        $email = Email::factory()->private()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Deal terms',
        ]);

        $this->person->emails()->attach($email->getKey());

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'requester_id' => $this->viewer->id,
            'owner_id' => $this->owner->id,
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->assertSee(__('filament/pages/email-inbox.pending_access.deny'))
            ->callAction(TestAction::make('denyAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });
});

describe('subject privacy enforcement', function (): void {
    it('shows (subject hidden) when the viewer cannot view the subject', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->assertSee('(subject hidden)')
            ->assertDontSee('Secret Subject');
    });

    it('shows the real subject when the viewer can view the subject', function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->assertSee('Real Subject')
            ->assertDontSee('(subject hidden)');
    });

    it('does not match a guessed subject when the viewer cannot view the subject', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->assertSeeHtml("email-list-row-{$email->getKey()}")
            ->set('search', 'Secret Subject')
            ->assertDontSeeHtml("email-list-row-{$email->getKey()}")
            ->assertSee(__('filament/pages/email-inbox.list_empty.no_results', ['search' => 'Secret Subject']));
    });

    it('leaves a teammate private email out of the list', function (): void {
        $this->actingAs($this->viewer);

        [$visible, $private] = collect([EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::PRIVATE])
            ->map(fn (EmailPrivacyTier $tier): Email => Email::factory()->create([
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->owner->id,
                'connected_account_id' => $this->account->getKey(),
                'privacy_tier' => $tier,
            ]))
            ->each(fn (Email $email) => $this->person->emails()->attach($email->getKey()))
            ->all();

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertSeeHtml("email-list-row-{$visible->getKey()}")
            ->assertDontSeeHtml("email-list-row-{$private->getKey()}");
    });

    it('returns no emails when a client calls the table records method directly', function (): void {
        $this->actingAs($this->viewer);

        foreach ([EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::PRIVATE] as $tier) {
            $email = Email::factory()->create([
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->owner->id,
                'connected_account_id' => $this->account->getKey(),
                'subject' => "Secret {$tier->value} subject",
                'privacy_tier' => $tier,
            ]);

            $this->person->emails()->attach($email->getKey());
        }

        $component = livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])->call('getTableRecords');

        expect(json_encode($component->effects['returns']))
            ->not->toContain('Secret metadata_only subject')
            ->not->toContain('Secret private subject');
    });

    it('matches the subject when searching as a viewer who can view it', function (): void {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
            ->set('search', 'Secret Subject')
            ->assertSeeHtml("email-list-row-{$email->getKey()}")
            ->assertSee('Secret Subject');
    });
});

it('badges the emails tab with the visible count for the record', function (): void {
    $this->person->forceFill(['email_count' => 99])->save();

    $visible = Email::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $this->person->emails()->attach($visible->modelKeys());

    expect(EmailsRelationManager::getBadge($this->person, ViewPeople::class))->toBe('2');
});

it('caps the emails tab badge at 99+', function (): void {
    $emails = Email::factory()->count(100)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $this->person->emails()->attach($emails->modelKeys());

    expect(EmailsRelationManager::getBadge($this->person, ViewPeople::class))->toBe('99+');
});

it('omits the emails tab badge when the viewer cannot see the linked mail', function (): void {
    $this->person->forceFill(['email_count' => 2])->save();

    $private = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $this->person->emails()->attach($private->getKey());

    $this->actingAs($this->viewer);

    expect(EmailsRelationManager::getBadge($this->person, ViewPeople::class))->toBeNull();
});
