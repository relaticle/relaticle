<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Inbox;

use App\Contracts\Inbox\RequestSource;
use App\Data\InboxRequest;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class EmailAccessRequestSource implements RequestSource
{
    public const string KIND = 'email_access';

    public function __construct(
        private ApproveEmailAccessRequestAction $approve,
        private DenyEmailAccessRequestAction $deny,
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
            ->with(['requester', 'email.workspace', 'email.participants'])
            ->latest()
            ->get()
            ->map(fn (EmailAccessRequest $request): InboxRequest => $this->present($request, $user));
    }

    public function find(User $user, Workspace $workspace, string $requestId): ?InboxRequest
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return null;
        }

        $request = $this->pending($user, $workspace)
            ->with(['requester', 'email.workspace', 'email.participants'])
            ->whereKey($requestId)
            ->first();

        return $request instanceof EmailAccessRequest ? $this->present($request, $user) : null;
    }

    public function countPendingFor(User $user, Workspace $workspace): int
    {
        if (! EmailIntegrationServiceProvider::enabled()) {
            return 0;
        }

        return EmailAccessRequest::countPendingIncomingFor($user, $workspace);
    }

    public function approve(User $user, Workspace $workspace, string $requestId): bool
    {
        return $this->approve->execute($this->findOwned($user, $workspace, $requestId), $user);
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

    private function present(EmailAccessRequest $request, User $owner): InboxRequest
    {
        $email = $request->email;
        $requester = $request->requester;

        return new InboxRequest(
            id: (string) $request->getKey(),
            kind: self::KIND,
            title: $requester === null
                ? __('filament/notifications/email-access-requested.title_without_requester')
                : __('filament/notifications/email-access-requested.title', ['name' => $requester->name]),
            body: $email?->displaySubject() ?? __('filament/pages/email-inbox.subject.none'),
            requester: $requester,
            createdAt: CarbonImmutable::instance($request->created_at),
            expiresAt: $request->deadline(),
            url: $email?->appUrlFor($owner),
        );
    }
}
