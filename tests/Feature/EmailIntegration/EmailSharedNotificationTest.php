<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\Notification as NotificationRow;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSharedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;

mutates(HasEmailReaderActions::class, EmailSharedNotification::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->alice = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->bob = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach([$this->alice->id => ['role' => 'member'], $this->bob->id => ['role' => 'member']]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $account->getKey(),
        'subject' => 'Renewal terms',
    ]);

    $this->person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->owner->id,
    ]);
    $this->person->emails()->attach($this->email->getKey());

    $this->mailedNotices = collect();
    Event::listen(NotificationSent::class, function (NotificationSent $sent): void {
        if ($sent->channel === 'mail' && $sent->notification instanceof EmailSharedNotification) {
            $this->mailedNotices->push($sent->notification);
        }
    });
});

/** @param  list<array{tier: EmailPrivacyTier, members: list<User>}>  $rows */
function saveSharingRows(array $rows, EmailPrivacyTier $emailTier = EmailPrivacyTier::METADATA_ONLY): Testable
{
    return livewire(EmailsRelationManager::class, ['ownerRecord' => test()->person, 'pageClass' => ViewPeople::class])
        ->callAction('manageSharing', data: [
            'privacy_tier' => $emailTier->value,
            'shares' => array_map(fn (array $row): array => [
                'tier' => $row['tier']->value,
                'shared_with' => array_map(fn (User $member): string => $member->getKey(), $row['members']),
            ], $rows),
        ], arguments: ['emailId' => test()->email->getKey()]);
}

/** @param  list<User>  $members */
function saveSharing(array $members, EmailPrivacyTier $tier = EmailPrivacyTier::FULL, EmailPrivacyTier $emailTier = EmailPrivacyTier::METADATA_ONLY): Testable
{
    return saveSharingRows($members === [] ? [] : [['tier' => $tier, 'members' => $members]], $emailTier);
}

it('notifies a member the email is newly shared with, once', function (): void {
    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentToTimes($this->alice, EmailSharedNotification::class, 1);
    Notification::assertNotSentTo($this->bob, EmailSharedNotification::class);
});

it('notifies nobody when a save keeps the existing shares', function (): void {
    saveSharing([$this->alice]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertNothingSent();
});

it('notifies only the member added on a later save', function (): void {
    saveSharing([$this->alice]);

    Notification::fake();

    saveSharing([$this->alice, $this->bob]);

    Notification::assertSentToTimes($this->bob, EmailSharedNotification::class, 1);
    Notification::assertNotSentTo($this->alice, EmailSharedNotification::class);
});

it('refuses a member listed in two rows and notifies nobody', function (): void {
    Notification::fake();

    saveSharingRows([
        ['tier' => EmailPrivacyTier::FULL, 'members' => [$this->alice]],
        ['tier' => EmailPrivacyTier::SUBJECT, 'members' => [$this->alice]],
    ])->assertHasActionErrors();

    Notification::assertNothingSent();
});

it('offers the sharer no share and notifies nobody when the sharer is submitted', function (): void {
    Notification::fake();

    saveSharing([$this->owner, $this->alice])->assertHasActionErrors();

    Notification::assertNothingSent();
});

it('notifies nobody and keeps the old shares when a member is outside the workspace', function (): void {
    saveSharing([$this->alice]);

    $outsider = User::factory()->withWorkspace()->create();

    Notification::fake();

    saveSharing([$this->bob, $outsider])->assertHasActionErrors();

    Notification::assertNothingSent();
    $this->assertDatabaseHas('email_shares', ['email_id' => $this->email->getKey(), 'shared_with' => $this->alice->getKey()]);
    $this->assertDatabaseMissing('email_shares', ['email_id' => $this->email->getKey(), 'shared_with' => $this->bob->getKey()]);
});

it('writes the row in the email workspace with the sharer as actor', function (): void {
    saveSharing([$this->alice], EmailPrivacyTier::SUBJECT);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();

    expect($row)->toBeInstanceOf(NotificationRow::class)
        ->and($row->workspace_id)->toBe($this->workspace->getKey())
        ->and($row->actor_id)->toBe($this->owner->getKey())
        ->and($row->subject_id)->toBe($this->email->getKey())
        ->and($row->reason)->toBe(NotificationReason::Shared)
        ->and($row->data)->toBe(['email_subject' => 'Renewal terms']);
});

it('turning off the in-app channel leaves the mail', function (): void {
    $this->alice->update(['notification_preferences' => ['email_shared' => ['in_app' => false]]]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentTo($this->alice, EmailSharedNotification::class, fn (EmailSharedNotification $notification, array $channels): bool => array_values($channels) === ['mail']);
});

it('turning off the email channel leaves the in-app row', function (): void {
    $this->alice->update(['notification_preferences' => ['email_shared' => ['email' => false]]]);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertSentTo($this->alice, EmailSharedNotification::class, fn (EmailSharedNotification $notification, array $channels): bool => $channels === ['database']);
});

it('sends a Participants-only share without the subject in the bell or the mail', function (): void {
    $this->owner->update(['name' => 'Dana Whitfield']);
    saveSharing([$this->alice], EmailPrivacyTier::METADATA_ONLY);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();
    $mail = $this->mailedNotices->sole()->toMail($this->alice);

    $this->actingAs($this->alice);
    $presented = EmailSharedNotification::present($row);

    expect($row->data['email_subject'])->toBeNull()
        ->and($presented->getTitle())->toBe('Dana Whitfield shared an email with you')
        ->and($presented->getBody())->toBeEmpty()
        ->and($presented->getActions())->toBeEmpty()
        ->and((string) $mail->render())->not->toContain('Renewal terms')
        ->and($mail->actionText)->toBe('Open Relaticle')
        ->and($mail->actionUrl)->not->toContain('email=');
});

it('sends nothing for a Private share', function (): void {
    Notification::fake();

    saveSharing([$this->alice], EmailPrivacyTier::PRIVATE);

    Notification::assertNothingSent();
});

it('sends nothing when the share is revoked before the notice goes out', function (): void {
    Queue::fake();

    saveSharing([$this->alice], emailTier: EmailPrivacyTier::PRIVATE);
    saveSharing([], emailTier: EmailPrivacyTier::PRIVATE);

    Queue::pushed(SendQueuedNotifications::class)->each(
        fn (SendQueuedNotifications $job) => unserialize(serialize($job))->handle(resolve(ChannelManager::class)),
    );

    expect($this->alice->notifications()->count())->toBe(0)
        ->and($this->mailedNotices)->toBeEmpty();
});

it('gives a Full share a reader link in the bell and the mail', function (): void {
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $row = $this->alice->notifications()->where('type', EmailSharedNotification::class)->sole();
    $mail = $this->mailedNotices->sole()->toMail($this->alice);

    $this->actingAs($this->alice);
    $presented = EmailSharedNotification::present($row);
    $link = Dashboard::getUrl(['email' => $this->email->getKey()], panel: 'app', tenant: $this->workspace);

    expect($presented->getBody())->toBe('Renewal terms')
        ->and($presented->getActions())->toHaveCount(1)
        ->and($presented->getActions()[0]->getUrl())->toBe($link)
        ->and($mail->actionText)->toBe('View email')
        ->and($mail->actionUrl)->toBe($link);
});

it('renders Markdown in the subject and the sharer name as text', function (): void {
    $this->owner->update(['name' => '[Boss](https://evil.example/name)']);
    $this->email->update(['subject' => '[x](https://evil.example) and www.evil.example and bad@evil.example']);
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $html = (string) $this->mailedNotices->sole()->toMail($this->alice)->render();

    expect($html)->not->toContain('<a href="https://evil.example')
        ->not->toContain('<a href="http://www.evil.example')
        ->not->toContain('<a href="mailto:bad@evil.example')
        ->toContain('[x](https://evil.example)');
});

it('keeps Markdown escapes out of the plain text mail', function (): void {
    $this->owner->update(['name' => 'J. Smith']);
    $this->email->update(['subject' => 'Re: Q3 (draft)']);
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $mail = $this->mailedNotices->sole()->toMail($this->alice);
    $text = (string) resolve(Markdown::class)->renderText($mail->markdown, $mail->data());

    expect($text)->toContain('J. Smith')
        ->toContain('Re: Q3 (draft)')
        ->not->toContain('\\');
});

it('keeps quotes and ampersands readable in the mail', function (): void {
    $this->owner->update(['name' => "Olivia O'Brien"]);
    $this->email->update(['subject' => "Let's meet about Q&A <draft>"]);
    saveSharing([$this->alice], EmailPrivacyTier::FULL);

    $html = (string) $this->mailedNotices->sole()->toMail($this->alice)->render();

    expect($html)->toContain("Olivia O'Brien")
        ->toContain("Let's meet about Q&amp;A &lt;draft&gt;")
        ->not->toContain('&amp;#039;')
        ->not->toContain('&amp;amp;')
        ->not->toContain('&amp;lt;');
});

it('leaves the email tier unchanged when a member is rejected', function (): void {
    $outsider = User::factory()->withWorkspace()->create();

    saveSharing([$outsider])->assertHasActionErrors();

    expect($this->email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('does not notify a member who already had access through another share', function (): void {
    resolve(EmailSharingService::class)->shareEmail($this->email, $this->bob, $this->alice, EmailPrivacyTier::FULL);

    Notification::fake();

    saveSharing([$this->alice]);

    Notification::assertNothingSent();
});
