<?php

declare(strict_types=1);

namespace App\Livewire\App\Workspaces\Concerns;

use App\Actions\Jetstream\InviteWorkspaceMember;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

trait SendsWorkspaceInvitations
{
    // Stops one authorized call queuing an unbounded number of mail sends.
    private const int MAX_INVITES_PER_SUBMISSION = 10;

    // Bounds cumulative volume per actor, which rateLimit() cannot: it counts
    // calls, not the emails each call queues.
    private const int MAX_INVITES_PER_WINDOW = 20;

    private const int INVITE_WINDOW_SECONDS = 60;

    /**
     * Addresses arrive pasted from a spreadsheet or mail client, so any
     * separator those produce counts: commas, semicolons, and whitespace.
     *
     * @return list<string>
     */
    private function parseEmails(string $input): array
    {
        $parts = preg_split('/[\s,;]+/', trim($input)) ?: [];

        $typedByCanonical = [];

        foreach ($parts as $email) {
            if ($email === '') {
                continue;
            }

            $typedByCanonical[EmailAddress::canonicalize($email)] ??= $email;
        }

        return array_values($typedByCanonical);
    }

    private function inviteRateLimitKey(): string
    {
        return 'invite-workspace-members:'.$this->authUser()->id;
    }

    private function inviteRetryAfterSeconds(): ?int
    {
        $rateLimitKey = $this->inviteRateLimitKey();

        if (! RateLimiter::tooManyAttempts($rateLimitKey, self::MAX_INVITES_PER_WINDOW)) {
            return null;
        }

        return RateLimiter::availableIn($rateLimitKey);
    }

    /**
     * @param  list<string>  $emails
     */
    private function sendInvitations(array $emails, string $role): void
    {
        if ($emails === []) {
            return;
        }

        // Volume-based, not call-based: the cap above bounds one submission,
        // this bounds cumulative volume from the same actor.
        $retryAfter = $this->inviteRetryAfterSeconds();

        if ($retryAfter !== null) {
            $this->sendNotification(
                __('workspaces.notifications.invite_rate_limited.title'),
                __('workspaces.notifications.invite_rate_limited.body', ['seconds' => $retryAfter]),
                'danger',
            );

            return;
        }

        RateLimiter::increment($this->inviteRateLimitKey(), self::INVITE_WINDOW_SECONDS, count($emails));

        $failures = [];
        $sent = 0;

        foreach ($emails as $email) {
            try {
                resolve(InviteWorkspaceMember::class)->invite($this->authUser(), $this->workspace, $email, $role);

                $sent++;
            } catch (ValidationException $exception) {
                $failures[] = "{$email}: {$exception->validator->errors()->first()}";
            }
        }

        if ($sent > 0) {
            $this->sendNotification(__('workspaces.notifications.workspace_invitation_sent.success'));
            $this->dispatch('workspaceInvitationSent');
        }

        if ($failures !== []) {
            $this->sendNotification(
                __('workspaces.notifications.some_invites_failed.title'),
                implode("\n", $failures),
                'warning',
            );
        }
    }
}
