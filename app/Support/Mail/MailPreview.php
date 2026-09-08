<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestTeamSection;
use App\Mail\NewContactSubmissionMail;
use App\Mail\ProTrialEndingSoonMail;
use App\Mail\SetupNudgeMail;
use App\Mail\TaskAssignedMail;
use App\Mail\TaskDigestMail;
use App\Mail\TeamInvitationMail;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Auth\NoticeOfEmailChangeRequest;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use App\Notifications\Auth\VerifyEmailChange;
use App\Notifications\TeamDeletionCancelledNotification;
use App\Notifications\TeamDeletionReminderNotification;
use App\Notifications\TeamDeletionScheduledNotification;
use App\Notifications\TeamMemberRemovedNotification;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\UserDeletionScheduledNotification;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use InvalidArgumentException;

final readonly class MailPreview
{
    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->registry());
    }

    public function render(string $name): string
    {
        $factory = $this->registry()[$name] ?? throw new InvalidArgumentException("Unknown mail preview [{$name}].");

        $team = $this->team();
        $rendered = $factory($this->owner($team), $team)->render();

        return $rendered instanceof Htmlable ? $rendered->toHtml() : $rendered;
    }

    /** @return array<string, Closure(User, Team): (Mailable|MailMessage)> */
    private function registry(): array
    {
        return [
            'trial-ending' => function (User $owner, Team $team): Mailable {
                $team->setRelation('owner', $owner);

                return new ProTrialEndingSoonMail($team);
            },
            'setup-nudge' => fn (User $owner, Team $team): Mailable => new SetupNudgeMail($owner, $team, 'first_record', url()->getAppUrl('chat')),
            'task-assigned' => fn (User $owner, Team $team): Mailable => new TaskAssignedMail('Call Ana Reyes about the renewal', url()->getAppUrl('tasks'), $team->name),
            'task-digest' => fn (User $owner): Mailable => new TaskDigestMail($owner, new DigestPayload([
                new DigestTeamSection('Acme Robotics', [
                    new DigestTaskItem('Send revised proposal', now()->subDays(2), url()->getAppUrl('tasks')),
                ], [
                    new DigestTaskItem('Call Ana Reyes about the renewal', now(), url()->getAppUrl('tasks')),
                    new DigestTaskItem('Prepare onboarding deck', now(), url()->getAppUrl('tasks')),
                ]),
            ])),
            'team-invitation' => fn (User $owner, Team $team): Mailable => new TeamInvitationMail(
                $this->invitation($owner, $team),
                'preview-token',
            ),
            'contact-submission' => fn (): Mailable => new NewContactSubmissionMail([
                'name' => 'Ana Reyes',
                'email' => 'ana@acme-robotics.example',
                'company' => 'Acme Robotics',
                'message' => 'We are evaluating CRMs for a 12-person sales team and need SSO. Can we talk this week?',
            ]),
            'team-deletion-scheduled' => fn (User $owner, Team $team): MailMessage => new TeamDeletionScheduledNotification($team)->toMail($owner),
            'team-deletion-reminder' => fn (User $owner, Team $team): MailMessage => new TeamDeletionReminderNotification($team)->toMail($owner),
            'team-deletion-cancelled' => fn (User $owner, Team $team): MailMessage => new TeamDeletionCancelledNotification($team)->toMail($owner),
            'team-member-removed' => fn (User $owner, Team $team): MailMessage => new TeamMemberRemovedNotification($team)->toMail($owner),
            'account-deletion-scheduled' => fn (User $owner): MailMessage => new UserDeletionScheduledNotification($owner)->toMail($owner),
            'account-deletion-reminder' => fn (User $owner): MailMessage => new UserDeletionReminderNotification($owner)->toMail($owner),
            'account-deletion-cancelled' => fn (User $owner): MailMessage => new UserDeletionCancelledNotification($owner)->toMail($owner),
            'verify-email' => function (User $owner): MailMessage {
                $notification = new VerifyEmail;
                $notification->url = url()->getAppUrl('email-verification/verify/preview');

                return $notification->toMail($owner);
            },
            'verify-email-change' => function (User $owner): MailMessage {
                $notification = new VerifyEmailChange;
                $notification->url = url()->getAppUrl('email-change-verification/verify/preview');

                return $notification->toMail($owner);
            },
            'email-change-notice' => fn (User $owner): MailMessage => new NoticeOfEmailChangeRequest('ada.new@example.com', url()->getAppUrl('email-change-verification/block/preview'))->toMail($owner),
            'reset-password' => function (User $owner): MailMessage {
                $notification = new ResetPassword('preview-token');
                $notification->url = url()->getAppUrl('password-reset/reset?token=preview');

                return $notification->toMail($owner);
            },
        ];
    }

    private function owner(Team $team): User
    {
        $owner = User::factory()->make([
            'id' => 1,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'timezone' => 'UTC',
            'current_team_id' => $team->id,
            'scheduled_deletion_at' => now()->addDays(30),
        ]);

        $owner->setRelation('currentTeam', $team);

        return $owner;
    }

    private function team(): Team
    {
        return Team::factory()->make([
            'id' => 1,
            'user_id' => 1,
            'name' => 'Acme Robotics',
            'trial_ends_at' => now()->addDays(3),
            'scheduled_deletion_at' => now()->addDays(30),
            'hosted_free_grandfathered_at' => null,
        ]);
    }

    private function invitation(User $owner, Team $team): TeamInvitation
    {
        $invitation = TeamInvitation::factory()->make([
            'id' => 1,
            'team_id' => $team->id,
            'email' => 'sam@acme-robotics.example',
            'role' => 'editor',
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->setRelation('team', $team);
        $invitation->setRelation('inviter', $owner);

        return $invitation;
    }
}
