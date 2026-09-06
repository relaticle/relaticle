<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamDeletionCancelledNotification;
use App\Notifications\TeamDeletionReminderNotification;
use App\Notifications\TeamDeletionScheduledNotification;
use App\Notifications\TeamMemberRemovedNotification;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\UserDeletionScheduledNotification;
use Illuminate\Mail\Markdown;

mutates(
    TeamDeletionScheduledNotification::class,
    TeamDeletionReminderNotification::class,
    TeamDeletionCancelledNotification::class,
    TeamMemberRemovedNotification::class,
    UserDeletionScheduledNotification::class,
    UserDeletionReminderNotification::class,
    UserDeletionCancelledNotification::class,
);

beforeEach(function (): void {
    config(['relaticle.deletion.reminder_days_before' => 5]);

    $this->owner = User::factory()->create(['name' => 'Ada Lovelace', 'timezone' => 'UTC']);
    $this->team = Team::factory()->create([
        'name' => 'Acme',
        'user_id' => $this->owner->id,
        'scheduled_deletion_at' => '2026-10-06 12:00:00',
    ]);
});

it('renders the team deletion scheduled mail', function (): void {
    $message = (new TeamDeletionScheduledNotification($this->team))->toMail($this->owner);
    $html = (string) $message->render();

    expect($message->subject)->toBe(__('mail.team_deletion_scheduled.subject', ['team' => 'Acme']))
        ->and($html)->toContain(__('mail.team_deletion_scheduled.heading', ['team' => 'Acme', 'date' => 'Oct 6, 2026']))
        ->and($html)->toContain(__('mail.team_deletion_scheduled.cta'))
        ->and($html)->toContain(__('mail.footer.reason.owner', ['team' => 'Acme']))
        ->and($html)->not->toContain('mail.');
});

it('renders the team deletion reminder with a pluralised subject', function (): void {
    $message = (new TeamDeletionReminderNotification($this->team))->toMail($this->owner);

    expect($message->subject)->toBe('Acme deletes in 5 days')
        ->and((string) $message->render())->toContain('5 days until Acme is deleted');
});

it('renders the team deletion cancelled mail', function (): void {
    $message = (new TeamDeletionCancelledNotification($this->team))->toMail($this->owner);

    expect($message->subject)->toBe(__('mail.team_deletion_cancelled.subject', ['team' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.team_deletion_cancelled.cta', ['team' => 'Acme']));
});

it('renders the member removed mail', function (): void {
    $member = User::factory()->create();
    $message = (new TeamMemberRemovedNotification($this->team))->toMail($member);

    expect($message->subject)->toBe(__('mail.team_member_removed.subject', ['team' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.team_member_removed.body', ['team' => 'Acme']))
        ->and((string) $message->render())->toContain(__('mail.footer.reason.former_member', ['team' => 'Acme']))
        ->and((string) $message->render())->not->toContain(__('mail.footer.reason.member', ['team' => 'Acme']));
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
        [(new TeamDeletionScheduledNotification($this->team))->toMail($this->owner), __('mail.team_deletion_scheduled.cta')],
        [(new TeamDeletionReminderNotification($this->team))->toMail($this->owner), __('mail.team_deletion_reminder.cta')],
        [(new TeamDeletionCancelledNotification($this->team))->toMail($this->owner), __('mail.team_deletion_cancelled.cta', ['team' => 'Acme'])],
        [(new TeamMemberRemovedNotification($this->team))->toMail($user), __('mail.team_member_removed.cta')],
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
