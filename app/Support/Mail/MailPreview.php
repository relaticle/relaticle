<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Data\DigestPayload;
use App\Data\DigestTaskItem;
use App\Data\DigestWorkspaceSection;
use App\Mail\NewContactSubmissionMail;
use App\Mail\ProTrialEndingSoonMail;
use App\Mail\SetupNudgeMail;
use App\Mail\TaskAssignedMail;
use App\Mail\TaskDigestMail;
use App\Mail\WorkspaceInvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\Auth\NoticeOfEmailChangeRequest;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use App\Notifications\Auth\VerifyEmailChange;
use App\Notifications\UserDeletionCancelledNotification;
use App\Notifications\UserDeletionReminderNotification;
use App\Notifications\UserDeletionScheduledNotification;
use App\Notifications\WorkspaceDeletionCancelledNotification;
use App\Notifications\WorkspaceDeletionReminderNotification;
use App\Notifications\WorkspaceDeletionScheduledNotification;
use App\Notifications\WorkspaceMemberRemovedNotification;
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

        $workspace = $this->workspace();
        $rendered = $factory($this->owner($workspace), $workspace)->render();

        return $rendered instanceof Htmlable ? $rendered->toHtml() : $rendered;
    }

    /** @return array<string, Closure(User, Workspace): (Mailable|MailMessage)> */
    private function registry(): array
    {
        return [
            'trial-ending' => function (User $owner, Workspace $workspace): Mailable {
                $workspace->setRelation('owner', $owner);

                return new ProTrialEndingSoonMail($workspace);
            },
            'setup-nudge' => fn (User $owner, Workspace $workspace): Mailable => new SetupNudgeMail($owner, $workspace, 'first_record', url()->getAppUrl('chat')),
            'task-assigned' => fn (User $owner, Workspace $workspace): Mailable => new TaskAssignedMail('Call Ana Reyes about the renewal', url()->getAppUrl('tasks'), $workspace->name),
            'task-digest' => fn (User $owner): Mailable => new TaskDigestMail($owner, new DigestPayload([
                new DigestWorkspaceSection('Acme Robotics', [
                    new DigestTaskItem('Send revised proposal', now()->subDays(2), url()->getAppUrl('tasks')),
                ], [
                    new DigestTaskItem('Call Ana Reyes about the renewal', now(), url()->getAppUrl('tasks')),
                    new DigestTaskItem('Prepare onboarding deck', now(), url()->getAppUrl('tasks')),
                ]),
            ])),
            'workspace-invitation' => fn (User $owner, Workspace $workspace): Mailable => new WorkspaceInvitationMail(
                $this->invitation($owner, $workspace),
                'preview-token',
            ),
            'contact-submission' => fn (): Mailable => new NewContactSubmissionMail([
                'name' => 'Ana Reyes',
                'email' => 'ana@acme-robotics.example',
                'company' => 'Acme Robotics',
                'message' => 'We are evaluating CRMs for a 12-person sales workspace and need SSO. Can we talk this week?',
            ]),
            'workspace-deletion-scheduled' => fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionScheduledNotification($workspace)->toMail($owner),
            'workspace-deletion-reminder' => fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionReminderNotification($workspace)->toMail($owner),
            'workspace-deletion-cancelled' => fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceDeletionCancelledNotification($workspace)->toMail($owner),
            'workspace-member-removed' => fn (User $owner, Workspace $workspace): MailMessage => new WorkspaceMemberRemovedNotification($workspace)->toMail($owner),
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

    private function owner(Workspace $workspace): User
    {
        $owner = User::factory()->make([
            'id' => 1,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'timezone' => 'UTC',
            'current_workspace_id' => $workspace->id,
            'scheduled_deletion_at' => now()->addDays(30),
        ]);

        $owner->setRelation('currentWorkspace', $workspace);

        return $owner;
    }

    private function workspace(): Workspace
    {
        return Workspace::factory()->make([
            'id' => 1,
            'user_id' => 1,
            'name' => 'Acme Robotics',
            'trial_ends_at' => now()->addDays(3),
            'scheduled_deletion_at' => now()->addDays(30),
            'hosted_free_grandfathered_at' => null,
        ]);
    }

    private function invitation(User $owner, Workspace $workspace): WorkspaceInvitation
    {
        $invitation = WorkspaceInvitation::factory()->make([
            'id' => 1,
            'workspace_id' => $workspace->id,
            'email' => 'sam@acme-robotics.example',
            'role' => 'editor',
            'expires_at' => now()->addDays(7),
        ]);

        $invitation->setRelation('workspace', $workspace);
        $invitation->setRelation('inviter', $owner);

        return $invitation;
    }
}
