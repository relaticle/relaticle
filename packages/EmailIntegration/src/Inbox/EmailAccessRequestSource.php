<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Inbox;

use App\Contracts\Inbox\RequestSource;
use App\Data\InboxRequest;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Queries\RecordEmailAccessQuery;

final readonly class EmailAccessRequestSource implements RequestSource
{
    public const string KIND = 'email_access';

    public function __construct(
        private ApproveEmailAccessRequestAction $approve,
        private DenyEmailAccessRequestAction $deny,
        private RecordEmailAccessQuery $recordMail,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    /** @return Collection<int, InboxRequest> */
    public function pendingFor(User $user, Workspace $workspace): Collection
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return new Collection;
        }

        return $this->pending($user, $workspace)
            ->with(['requester', 'owner', 'email.workspace', 'email.participants',
                'email.companies' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
                'email.people' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
                'email.opportunities' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
            ])
            ->latest()
            ->get()
            ->map(fn (EmailAccessRequest $request): InboxRequest => $this->present($request));
    }

    public function find(User $user, Workspace $workspace, string $requestId): ?InboxRequest
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return null;
        }

        $request = $this->pending($user, $workspace)
            ->with(['requester', 'owner', 'email.workspace', 'email.participants',
                'email.companies' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
                'email.people' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
                'email.opportunities' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
            ])
            ->whereKey($requestId)
            ->first();

        return $request instanceof EmailAccessRequest ? $this->present($request) : null;
    }

    public function countPendingFor(User $user, Workspace $workspace): int
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return 0;
        }

        return EmailAccessRequest::countPendingIncomingFor($user, $workspace);
    }

    public function approve(User $user, Workspace $workspace, string $requestId, ?string $option = null): bool
    {
        $request = $this->findOwned($user, $workspace, $requestId);
        $record = null;

        if ($option !== null) {
            $record = $this->recordAnswers($request)->first(fn (Company|People|Opportunity $record): bool => $record->getMorphClass().':'.$record->getKey() === $option);
            abort_unless($record !== null, 422);
        }

        return $this->approve->execute($request, $user, $record);
    }

    public function deny(User $user, Workspace $workspace, string $requestId): bool
    {
        return $this->deny->execute($this->findOwned($user, $workspace, $requestId), $user);
    }

    /** @return Builder<EmailAccessRequest> */
    private function owned(User $user, Workspace $workspace): Builder
    {
        return EmailAccessRequest::query()
            ->where('owner_id', $user->getKey())
            ->where('workspace_id', $workspace->getKey());
    }

    /** @return Builder<EmailAccessRequest> */
    private function pending(User $user, Workspace $workspace): Builder
    {
        return EmailAccessRequest::query()->pendingIncomingFor($user, $workspace);
    }

    private function findOwned(User $user, Workspace $workspace, string $requestId): EmailAccessRequest
    {
        abort_unless(EmailIntegrationServiceProvider::enabled(), 404);

        return $this->owned($user, $workspace)->whereKey($requestId)->firstOrFail();
    }

    private function present(EmailAccessRequest $request): InboxRequest
    {
        $email = $request->email;
        $requester = $request->requester;

        return new InboxRequest(
            id: (string) $request->getKey(),
            kind: self::KIND,
            title: EmailAccessRequestedNotification::titleFor($request),
            body: $this->body($request),
            requester: $requester,
            createdAt: CarbonImmutable::instance($request->created_at),
            expiresAt: $request->deadline(),
            url: $email?->appUrl(),
            approvalOptions: $this->recordAnswers($request)->mapWithKeys(fn (Company|People|Opportunity $record): array => [
                $record->getMorphClass().':'.$record->getKey() => __('filament/pages/email-access-requests.actions.share_record.label', ['record' => $record->name]),
            ])->all(),
        );
    }

    /** @return Collection<int, Company|People|Opportunity> */
    private function recordAnswers(EmailAccessRequest $request): Collection
    {
        $email = $request->email;

        return $email !== null && $email->user_id === $request->owner_id
            ? $this->recordMail->recordsOf($email)->take(3)
            : new Collection;
    }

    private function body(EmailAccessRequest $request): ?string
    {
        if ($request->isForRecord()) {
            return $request->note;
        }

        $subject = $request->email?->displaySubject() ?? __('filament/pages/email-inbox.subject.none');

        return filled($request->note)
            ? __('filament/notifications/email-access-requested.body_with_note', ['subject' => $subject, 'note' => $request->note])
            : $subject;
    }
}
