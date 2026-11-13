<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Actions\Notifications\ArchiveNotification;
use App\Data\InboxRequest;
use App\Enums\Notifications\RequestOutcome;
use App\Filament\Pages\NotificationPreferences;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use App\Support\CurrentWorkspace;
use App\Support\Inbox\InboxRequests;
use App\Support\PlainText;
use Carbon\CarbonImmutable;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

final class AppDatabaseNotifications extends DatabaseNotifications
{
    /** @var array<string, array{outcome: string, title: string, body: ?string, avatar: ?string, created_at: string}> */
    #[Locked]
    public array $decidedRequests = [];

    #[Locked]
    public ?string $announcedThrough = null;

    private ?string $announcement = null;

    public function mount(): void
    {
        $this->announcedThrough = $this->inboxStats()['newest'];
    }

    public function getTrigger(): View
    {
        return view('filament.app.notifications-trigger');
    }

    public function placeholder(): string
    {
        $workspace = resolve(CurrentWorkspace::class)->get();

        return '<div>'.$this->getTrigger()->with([
            'unreadNotificationsCount' => $this->getUnreadNotificationsCount(),
            'pendingRequestsCount' => $workspace instanceof Workspace ? resolve(InboxRequests::class)->countFor($this->requireUser(), $workspace) : 0,
            'isPlaceholder' => true,
        ])->render().'</div>';
    }

    /** @return MorphMany<NotificationRow, User> */
    public function getNotificationsQuery(): MorphMany
    {
        return $this->requireUser()->notifications()
            ->visibleIn(resolve(CurrentWorkspace::class)->get())
            ->orderByDesc('id');
    }

    public function archiveNotification(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);

        $row = $this->getNotificationsQuery()->findOrFail($id);

        resolve(ArchiveNotification::class)->execute($this->requireUser(), $row);

        $this->announcement = __('notifications.panel.announcements.archived', ['title' => $this->plainText($row)]);
    }

    public function clearNotifications(): void
    {
        $archive = resolve(ArchiveNotification::class);
        $user = $this->requireUser();

        foreach ($this->getNotificationsQuery()->get() as $row) {
            $archive->execute($user, $row);
        }
    }

    #[On('notificationClosed')]
    public function removeNotification(string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }

        $row = $this->getNotificationsQuery()->whereKey($id)->first();

        if (! $row instanceof NotificationRow) {
            return;
        }

        resolve(ArchiveNotification::class)->execute($this->requireUser(), $row);
    }

    public function forgetDecidedRequests(): void
    {
        $this->decidedRequests = [];
    }

    public function approveRequest(string $kind, string $id): void
    {
        $this->decideRequest($kind, $id, RequestOutcome::Approved);
    }

    public function denyRequest(string $kind, string $id): void
    {
        $this->decideRequest($kind, $id, RequestOutcome::Denied);
    }

    public function render(): View
    {
        $user = $this->requireUser();
        $workspace = resolve(CurrentWorkspace::class)->get();
        $notifications = $this->getNotifications();
        $stats = $this->inboxStats();
        $unreadCount = $stats['unread'];
        $pending = $workspace instanceof Workspace ? resolve(InboxRequests::class)->for($user, $workspace) : new Collection;

        $this->loadPresentedRelations($notifications);

        return view('livewire.app.database-notifications', [
            'groups' => $this->groupByDay($notifications, $user->effectiveTimezone()),
            'present' => $this->presentNotification(...),
            'requests' => $this->requestRows($pending),
            'unreadCount' => $unreadCount,
            'pendingCount' => $pending->count(),
            'announcement' => $this->announcementText($this->takeNewCount($stats)),
            'hasOlderNotifications' => $notifications instanceof Paginator && $notifications->hasMorePages(),
            'pageSize' => $notifications instanceof Paginator ? $notifications->perPage() : null,
            'settingsUrl' => $workspace instanceof Workspace ? NotificationPreferences::getUrl(tenant: $workspace) : null,
        ]);
    }

    private function decideRequest(string $kind, string $id, RequestOutcome $outcome): void
    {
        $user = $this->requireUser();
        $workspace = resolve(CurrentWorkspace::class)->get();

        abort_unless($workspace instanceof Workspace, 404);

        $requests = resolve(InboxRequests::class);
        $request = $requests->find($user, $workspace, $kind, $id);

        $decided = $outcome === RequestOutcome::Approved
            ? $requests->approve($user, $workspace, $kind, $id)
            : $requests->deny($user, $workspace, $kind, $id);

        if (! $request instanceof InboxRequest) {
            return;
        }

        $recorded = $decided ? $outcome : RequestOutcome::AlreadyDecided;

        $this->announcement = $recorded->announcement($request->title);

        $this->decidedRequests[$id] = [
            'outcome' => $recorded->value,
            'title' => $request->title,
            'body' => $request->body,
            'avatar' => $request->requester instanceof User ? filament()->getUserAvatarUrl($request->requester) : null,
            'created_at' => $request->createdAt->toIso8601String(),
        ];
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** @param  array{unread: int, fresh: int, newest: ?string}  $stats */
    private function takeNewCount(array $stats): int
    {
        $new = $this->announcedThrough === null ? $stats['unread'] : $stats['fresh'];

        $this->announcedThrough = $stats['newest'];

        return $new;
    }

    /** @return array{unread: int, fresh: int, newest: ?string} */
    private function inboxStats(): array
    {
        $stats = $this->getNotificationsQuery()
            ->reorder()
            ->toBase()
            ->selectRaw(
                'count(*) filter (where read_at is null) as unread, count(*) filter (where read_at is null and created_at > ?) as fresh, max(created_at) as newest',
                [$this->announcedThrough],
            )
            ->first();

        return [
            'unread' => (int) $stats?->unread,
            'fresh' => (int) $stats?->fresh,
            'newest' => is_string($stats?->newest) ? $stats->newest : null,
        ];
    }

    private function announcementText(int $newCount): ?string
    {
        $text = collect([
            $this->announcement,
            $newCount > 0 ? trans_choice('notifications.panel.announce', $newCount, ['count' => $newCount]) : null,
        ])->filter()->implode(' ');

        return $text === '' ? null : $text;
    }

    private function plainText(NotificationRow $row): string
    {
        $presented = $this->presentNotification($row);

        return collect([$presented->getTitle(), $presented->getBody()])
            ->filter()
            ->map(PlainText::fromHtml(...))
            ->implode('. ');
    }

    /**
     * @param  DatabaseNotificationCollection<int, NotificationRow>|Paginator<int, NotificationRow>  $notifications
     * @return array<string, list<NotificationRow>>
     */
    private function groupByDay(DatabaseNotificationCollection|Paginator $notifications, string $timezone): array
    {
        $startOfToday = today($timezone);
        $startOfYesterday = $startOfToday->subDay();
        $groups = ['today' => [], 'yesterday' => [], 'earlier' => []];
        $rows = $notifications instanceof Paginator ? $notifications->items() : $notifications->all();

        foreach ($rows as $row) {
            $createdAt = $row->created_at;

            $key = match (true) {
                $createdAt === null || $createdAt->greaterThanOrEqualTo($startOfToday) => 'today',
                $createdAt->greaterThanOrEqualTo($startOfYesterday) => 'yesterday',
                default => 'earlier',
            };

            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * @param  Collection<int, InboxRequest>  $pending
     * @return array<int, array{
     *     id: string,
     *     kind: string,
     *     title: string,
     *     body: ?string,
     *     avatar: ?string,
     *     createdAt: CarbonImmutable,
     *     expiresAt: ?CarbonImmutable,
     *     url: ?string,
     *     outcome: ?RequestOutcome
     * }>
     */
    private function requestRows(Collection $pending): array
    {
        $rows = $pending
            ->map(fn (InboxRequest $request): array => [
                'id' => $request->id,
                'kind' => $request->kind,
                'title' => $request->title,
                'body' => $request->body,
                'avatar' => $request->requester instanceof User ? filament()->getUserAvatarUrl($request->requester) : null,
                'createdAt' => $request->createdAt,
                'expiresAt' => $request->expiresAt,
                'url' => $request->url,
                'outcome' => null,
            ]);

        $decided = collect($this->decidedRequests)
            ->reject(fn (array $row, string $id): bool => $pending->contains('id', $id))
            ->map(fn (array $row, string $id): array => [
                'id' => $id,
                'kind' => '',
                'title' => $row['title'],
                'body' => $row['body'],
                'avatar' => $row['avatar'],
                'createdAt' => CarbonImmutable::parse($row['created_at']),
                'expiresAt' => null,
                'url' => null,
                'outcome' => RequestOutcome::tryFrom($row['outcome']),
            ]);

        return $rows->concat($decided)->sortByDesc('createdAt')->values()->all();
    }

    private function presentNotification(DatabaseNotification $notification): FilamentNotification
    {
        $type = $notification->type;

        if (! $notification instanceof NotificationRow || $notification->reason === null || ! is_subclass_of($type, InboxNotification::class)) {
            return $this->getNotification($notification);
        }

        return $type::present($notification)
            ->date($this->formatNotificationDate($notification->getAttributeValue('created_at')));
    }

    /** @param  DatabaseNotificationCollection<int, NotificationRow>|Paginator<int, NotificationRow>  $notifications */
    private function loadPresentedRelations(DatabaseNotificationCollection|Paginator $notifications): void
    {
        $rows = $notifications instanceof Paginator
            ? new EloquentCollection($notifications->items())
            : $notifications;

        $rows->load(['actor', 'workspace', 'subject']);

        $rows->groupBy('type')->each(function (EloquentCollection $ofType, string $type): void {
            if (! is_subclass_of($type, InboxNotification::class) || $type::presentingRelations() === []) {
                return;
            }

            new EloquentCollection($ofType->pluck('subject')->filter()->values()->all())->loadMissing($type::presentingRelations());
        });
    }
}
