<?php

declare(strict_types=1);

use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\EmailsRelationManager as OpportunityEmailsRelationManager;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\EmailSearchService;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(BaseEmailsRelationManager::class, EmailVisibilityService::class, EmailSearchService::class);

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
            EmailAccessRequest::query()->where('email_id', $email->getKey())
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
            EmailAccessRequest::query()->where('email_id', $email->getKey())
                ->where('requester_id', $this->viewer->id)
                ->count()
        )->toBe(1);
    });

    it('is offered again once the previous request is overdue', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach($email->getKey());

        EmailAccessRequest::factory()->overdue()->create([
            'email_id' => $email->getKey(),
            'requester_id' => $this->viewer->id,
            'owner_id' => $this->owner->id,
        ]);

        $component = livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertActionVisible('requestAccess', ['emailId' => $email->getKey()]);

        $row = collect($component->instance()->emails()->items())->firstWhere('id', $email->getKey());

        expect($row->viewer_has_pending_access_request)->toBeFalsy();
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

    it('lets a teammate clear their own unread state on a shared email', function (): void {
        [$newer, $older] = collect([now(), now()->subHour()])
            ->map(fn (CarbonImmutable $sentAt): Email => Email::factory()->inbound()->full()->create([
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->owner->id,
                'connected_account_id' => $this->account->getKey(),
                'sent_at' => $sentAt,
            ]))
            ->each(fn (Email $email) => $this->person->emails()->attach($email->getKey()))
            ->all();

        $this->actingAs($this->viewer);

        $tab = livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ]);

        expect(EmailRead::query()->where('user_id', $this->viewer->id)->exists())->toBeFalse();

        $tab->call('selectEmail', $older->getKey());

        expect(EmailRead::query()->where('user_id', $this->viewer->id)->pluck('email_id')->all())->toBe([$older->getKey()]);

        $tab->call('selectEmail', $newer->getKey());

        expect(EmailRead::query()->where('user_id', $this->viewer->id)->pluck('email_id')->all())
            ->toEqualCanonicalizing([$older->getKey(), $newer->getKey()]);
    });

    it('keeps each viewer read state independent of the owner', function (): void {
        [$newer, $older] = collect([now(), now()->subHour()])
            ->map(fn (CarbonImmutable $sentAt): Email => Email::factory()->inbound()->full()->create([
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->owner->id,
                'connected_account_id' => $this->account->getKey(),
                'sent_at' => $sentAt,
            ]))
            ->each(fn (Email $email) => $this->person->emails()->attach($email->getKey()))
            ->all();

        $this->actingAs($this->viewer);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $older->getKey())
            ->call('selectEmail', $newer->getKey());

        $this->actingAs($this->owner);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ]);

        expect(EmailRead::query()->where('user_id', $this->viewer->id)->count())->toBe(2)
            ->and(EmailRead::query()->where('user_id', $this->owner->id)->exists())->toBeFalse();
    });
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

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
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

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
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

    it('keeps the opening of the message out of the reader for a viewer at the subject level', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Renewal terms',
            'snippet' => 'The discount is forty percent',
            'privacy_tier' => EmailPrivacyTier::SUBJECT,
        ]);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $email->getKey())
            ->assertSee('Renewal terms')
            ->assertDontSee('The discount is forty percent');
    });

    it('shows the snippet in the list row only, not again under the reader subject', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Renewal terms',
            'snippet' => 'The discount is forty percent',
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);
        EmailBody::create(['email_id' => $email->id, 'body_html' => '<p>Full body</p>', 'body_text' => 'Full body']);

        $this->person->emails()->attach($email->getKey());

        $html = livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $email->getKey())
            ->assertSee('Renewal terms')
            ->html();

        expect(substr_count($html, 'The discount is forty percent'))->toBe(1);
    });

    it('renders a text-only body in the app theme without a frame', function (): void {
        $this->actingAs($this->viewer);

        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);
        EmailBody::create(['email_id' => $email->id, 'body_html' => null, 'body_text' => 'Plain hello from Brian']);

        $this->person->emails()->attach($email->getKey());

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->call('selectEmail', $email->getKey())
            ->assertSee('Plain hello from Brian')
            ->assertDontSeeHtml('<iframe');
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

    it('lists a message two members synced once, as its most open copy', function (): void {
        $this->actingAs($this->viewer);

        $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->workspace->users()->attach($teammate, ['role' => 'member']);
        $teammateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
        ]));

        $open = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'rfc_message_id' => '<renewal-thread@example.com>',
            'subject' => 'Renewal terms',
            'sent_at' => CarbonImmutable::parse('2026-10-01 09:00:00'),
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);
        $closed = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
            'connected_account_id' => $teammateAccount->getKey(),
            'rfc_message_id' => '<renewal-thread@example.com>',
            'subject' => 'Renewal terms',
            'sent_at' => CarbonImmutable::parse('2026-10-02 09:00:00'),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ]);

        $this->person->emails()->attach([$open->getKey(), $closed->getKey()]);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])
            ->assertSeeHtml("email-list-row-{$open->getKey()}")
            ->assertDontSeeHtml("email-list-row-{$closed->getKey()}")
            ->assertSee('Renewal terms')
            ->assertDontSee('(subject hidden)');
    });

    it('returns no emails when a client calls the table records method directly', function (): void {
        $this->actingAs($this->viewer);

        livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])->call('getTableRecords')->assertForbidden();
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

it('badges the opportunity tab with the mail its window shows', function (): void {
    $this->travelTo(now()->setDate(2026, 6, 1));
    $company = Company::factory()->create(['workspace_id' => $this->workspace->id]);
    $opportunity = Opportunity::factory()->create(['workspace_id' => $this->workspace->id, 'company_id' => $company->id]);
    $mail = fn (int $daysAgo): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now()->subDays($daysAgo),
    ]);

    $company->emails()->attach([$mail(10)->id, $mail(90)->id], ['link_source' => 'auto']);
    $opportunity->emails()->attach($mail(400)->id, ['link_source' => 'manual']);

    expect(OpportunityEmailsRelationManager::getBadge($opportunity, ViewOpportunity::class))->toBe('2');
});

it('loads the emails tab badge after the record page renders', function (): void {
    expect(EmailsRelationManager::getTabComponent($this->person, ViewPeople::class)->isBadgeDeferred())->toBeTrue();
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

it('warns on the record tab about the viewer own stale mailbox', function (): void {
    $this->account->forceFill(['health' => MailboxHealth::Stale, 'health_changed_at' => now(), 'last_synced_at' => now()->subDays(2)])->save();

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->assertSeeHtml('data-mailbox-health="stale"');

    $this->actingAs($this->viewer);

    livewire(EmailsRelationManager::class, ['ownerRecord' => $this->person, 'pageClass' => ViewPeople::class])
        ->assertDontSeeHtml('data-mailbox-health');
});

describe('search', function (): void {
    beforeEach(function (): void {
        $this->linkedEmail = function (array $attributes = [], ?string $bodyText = null): Email {
            $email = Email::factory()->create([
                'workspace_id' => $this->workspace->id,
                'user_id' => $this->owner->id,
                'connected_account_id' => $this->account->getKey(),
                'subject' => 'Orchard update',
                'snippet' => 'Notes from the grower',
                ...$attributes,
            ]);

            if ($bodyText !== null) {
                EmailBody::create(['email_id' => $email->id, 'body_html' => null, 'body_text' => $bodyText]);
            }

            $this->person->emails()->syncWithoutDetaching([$email->getKey()]);

            return $email;
        };

        $this->searchTab = fn (string $search): Testable => livewire(EmailsRelationManager::class, [
            'ownerRecord' => $this->person,
            'pageClass' => ViewPeople::class,
        ])->set('search', $search);
    });

    it('matches a body word for the owner but not for a metadata-tier teammate', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY], 'The kumquat harvest is late this year');

        ($this->searchTab)('kumquat')->assertSeeHtml("email-list-row-{$email->getKey()}");

        $this->actingAs($this->viewer);

        ($this->searchTab)('kumquat')
            ->assertDontSeeHtml("email-list-row-{$email->getKey()}")
            ->assertSee(__('filament/pages/email-inbox.list_empty.no_results', ['search' => 'kumquat']));
    });

    it('matches a body word within the first 20,000 characters and not one past them', function (): void {
        $filler = str_repeat('a ', 9_995);
        $email = ($this->linkedEmail)([], $filler.'kumquat '.str_repeat('b ', 10).'quince');

        ($this->searchTab)('kumquat')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('quince')->assertDontSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('matches a subject word at subject tier but not a body word', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::SUBJECT, 'subject' => 'Orchard pricing'], 'The kumquat harvest is late this year');

        $this->actingAs($this->viewer);

        ($this->searchTab)('pricing')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('kumquat')->assertDontSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('applies a metadata share over a full email default', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL], 'The kumquat harvest is late this year');

        EmailShare::factory()->tier(EmailPrivacyTier::METADATA_ONLY)->create([
            'email_id' => $email->getKey(),
            'workspace_id' => $this->workspace->id,
            'shared_by' => $this->owner->id,
            'shared_with' => $this->viewer->id,
        ]);

        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => 'grower@orchard.test',
            'name' => 'Grower',
            'role' => EmailParticipantRole::FROM,
        ]);

        $this->actingAs($this->viewer);

        ($this->searchTab)('orchard.test')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('kumquat')->assertDontSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('finds an address by a fragment in the middle', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => 'brian.chesky@airbnb.com',
            'name' => 'Brian Chesky',
            'role' => EmailParticipantRole::FROM,
        ]);

        $this->actingAs($this->viewer);

        ($this->searchTab)('irbnb')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('AIRBNB')->assertSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('finds a word stored after the email was created', function (): void {
        $email = resolve(StoreEmailAction::class)->execute($this->account, new FetchedEmailData(
            providerMessageId: 'msg-kumquat',
            rfcMessageId: '<kumquat@example.com>',
            threadId: 'thread-kumquat',
            inReplyTo: null,
            subject: 'Orchard update',
            snippet: 'Notes from the grower',
            sentAt: now(),
            direction: EmailDirection::INBOUND,
            folder: EmailFolder::Inbox,
            hasAttachments: false,
            isRead: false,
            bodyText: 'The kumquat harvest is late this year',
            bodyHtml: null,
            participants: [['email_address' => 'grower@orchard.test', 'name' => 'Grower', 'role' => 'from']],
            attachments: [],
        ));

        $this->person->emails()->syncWithoutDetaching([$email->getKey()]);

        ($this->searchTab)('kumquat')->assertSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('matches the last word as a prefix', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL], 'The kumquat harvest is late this year');

        $this->actingAs($this->viewer);

        ($this->searchTab)('kumq')->assertSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('does not search subjects and bodies for one character', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL], 'The kumquat harvest is late this year');

        $sender = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL, 'subject' => 'Hello']);
        EmailParticipant::query()->create(['email_id' => $sender->id, 'email_address' => 'k@grower.test', 'name' => null, 'role' => EmailParticipantRole::FROM]);

        ($this->searchTab)('ku')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('k')
            ->assertDontSeeHtml("email-list-row-{$email->getKey()}")
            ->assertSeeHtml("email-list-row-{$sender->getKey()}");
    });

    it('cuts an over-long search at 200 characters and drops null bytes', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL], 'The kumquat harvest is late this year');

        ($this->searchTab)('kumquat'.str_repeat(' ', 300).'zebra')->assertSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)("kum\0quat")->assertSeeHtml("email-list-row-{$email->getKey()}");
    });

    it('matches no subject or body for a search of only excluded words', function (): void {
        $email = ($this->linkedEmail)(['privacy_tier' => EmailPrivacyTier::FULL], 'The kumquat harvest is late this year');

        ($this->searchTab)('-pricing')->assertDontSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('- "price list"')->assertDontSeeHtml("email-list-row-{$email->getKey()}");
        ($this->searchTab)('orchard -pricing')->assertSeeHtml("email-list-row-{$email->getKey()}");

        EmailParticipant::query()->create(['email_id' => $email->id, 'email_address' => 'orchard-pricing@grower.test', 'name' => null, 'role' => EmailParticipantRole::FROM]);

        ($this->searchTab)('-pricing')->assertSeeHtml("email-list-row-{$email->getKey()}");
    });
});
