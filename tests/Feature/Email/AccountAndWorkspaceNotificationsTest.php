<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\UserDeletionScheduledNotification;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use App\Notifications\WorkspaceMemberRemovedNotification;
use Illuminate\Mail\Markdown;

mutates(
    WorkspaceDeletionScheduledNotification::class,
    WorkspaceDeletionReminderNotification::class,
    WorkspaceDeletionCancelledNotification::class,
    WorkspaceMemberRemovedNotification::class,
    UserDeletionScheduledNotification::class,
    UserDeletionReminderNotification::class,
    UserDeletionCancelledNotification::class,
);

beforeEach(function (): void {
    config(['relaticle.deletion.reminder_days_before' => 5]);

    $this->owner = User::factory()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $this->owner->id,
        'scheduled_deletion_at' => '2026-10-06 12:00:00',
    ]);
});

it('renders the workspace deletion scheduled mail', function (): void {
    $message = (new WorkspaceDeletionScheduledNotification($this->workspace))->toMail($this->owner);
    $html = (string) $message->render();

    expect($message->subject)->toBe(__('mail.workspace_deletion_scheduled.subject', ['workspace' => 'Acme']))
        ->and($html)->toContain(__('mail.workspace_deletion_scheduled.heading', ['workspace' => 'Acme', 'date' => 'Oct 6, 2026']))
        ->and($html)->toContain(__('mail.workspace_deletion_scheduled.cta'))
        ->and($html)->toContain(__('mail.footer.reason.owner', ['workspace' => 'Acme']))
        ->and($html)->not->toContain('mail.');
});

it('renders the workspace deletion reminder with a pluralised subject', function (): void {
    $message = (new WorkspaceDeletionReminderNotification($this->workspace))->toMail($this->owner);

    expect($message->subject)->toBe('Acme deletes in 5 days')
        ->and((string) $message->render())->toContain('5 days until Acme is deleted');
});

it('renders the workspace deletion cancelled mail', function (): void {
    $message = (new WorkspaceDeletionCancelledNotification($this->workspace))->toMail($this->owner);

    expect($message->subject)->toBe(__('mail.workspace_deletion_cancelled.subject', ['workspace' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.workspace_deletion_cancelled.cta', ['workspace' => 'Acme']));
});

it('renders the member removed mail', function (): void {
    $member = User::factory()->create();
    $message = (new WorkspaceMemberRemovedNotification($this->workspace))->toMail($member);

    expect($message->subject)->toBe(__('mail.workspace_member_removed.subject', ['workspace' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.workspace_member_removed.body', ['workspace' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.footer.reason.former_member', ['workspace' => 'Acme']))
        ->and((string) $message->render())->not->toContain(__('mail.footer.reason.member', ['workspace' => 'Acme']));
});

it('renders the account deletion mails in the user timezone', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'timezone' => 'Asia/Tokyo', 'scheduled_deletion_at' => '2026-10-06 20:00:00']);

    $scheduled = (new UserDeletionScheduledNotification($user))->toMail($user);
    $reminder = (new UserDeletionReminderNotification($user))->toMail($user);
    $cancelled = (new UserDeletionCancelledNotification($user))->toMail($user);

    expect((string) $scheduled->render())->toContain(__('mail.account_deletion_scheduled.heading', ['date' => 'Oct 7, 2026']))
        ->and($reminder->subject)->toBe('Your account deletes in 5 days')
        ->and((string) $cancelled->render())->toContain(__('mail.account_deletion_cancelled.heading', ['name' => 'Ada']));
});

it('carries heading, cta label, and cta url in the plain-text part of every notification', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC', 'scheduled_deletion_at' => '2026-10-06 12:00:00']);
    $markdown = resolve(Markdown::class);

    $cases = [
        [(new WorkspaceDeletionScheduledNotification($this->workspace))->toMail($this->owner), __('mail.workspace_deletion_scheduled.cta')],
        [(new WorkspaceDeletionReminderNotification($this->workspace))->toMail($this->owner), __('mail.workspace_deletion_reminder.cta')],
        [(new WorkspaceDeletionCancelledNotification($this->workspace))->toMail($this->owner), __('mail.workspace_deletion_cancelled.cta', ['workspace' => 'Acme'])],
        [(new WorkspaceMemberRemovedNotification($this->workspace))->toMail($user), __('mail.workspace_member_removed.cta')],
        [(new UserDeletionScheduledNotification($user))->toMail($user), __('mail.account_deletion_scheduled.cta')],
        [(new UserDeletionReminderNotification($user))->toMail($user), __('mail.account_deletion_reminder.cta')],
        [(new UserDeletionCancelledNotification($user))->toMail($user), __('mail.account_deletion_cancelled.cta')],
    ];

    foreach ($cases as [$message, $cta]) {
        $html = (string) $message->render();
        $text = (string) $markdown->renderText($message->markdown, $message->data());

        preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $heading);
        preg_match('/<a href="([^"]+)" class="button button-primary"/', $html, $button);

        expect($heading)->not->toBeEmpty()
            ->and($button)->not->toBeEmpty()
            ->and($text)->toContain(trim(html_entity_decode(strip_tags($heading[1]))))
            ->and($text)->toContain($cta.': '.$button[1]);
    }
});
