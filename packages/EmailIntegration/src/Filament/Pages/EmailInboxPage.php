<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use App\Models\Workspace;
use Filament\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationPage;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasConversationsFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailReaderActions;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

/**
 * @property-read ConversationView $activeView
 * @property-read array{needs_reply: int, unlinked: int, automated: int} $viewCounts
 * @property-read ConversationPage $conversations
 * @property-read Email|null $selectedEmail
 * @property-read SupportCollection<int, EmailAccessRequest> $pendingAccessRequests
 */
final class EmailInboxPage extends Page
{
    use HasConversationsFlag;
    use HasEmailFeatureFlag;
    use HasEmailReaderActions;

    protected string $view = 'email-integration::filament.pages.email-inbox';

    protected static ?string $navigationLabel = null;

    protected static ?string $slug = 'email';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $workspace = filament()->getTenant();

        if (! $user instanceof User || ! $workspace instanceof Workspace) {
            return null;
        }

        if (ConnectedAccount::query()->ownedBy($user, $workspace)->connected()->unhealthy()->doesntExist()) {
            return null;
        }

        return __('filament/pages/email-inbox.health.nav_badge');
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return __('filament/pages/email-inbox.health.nav_tooltip');
    }

    public function getTitle(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
    }

    public function getHeadingIcon(): string|\BackedEnum|Htmlable|null
    {
        return self::getNavigationIcon();
    }

    /**
     * Which of the lists (drafts, outbox, templates) the page is showing. The tab
     * bodies are nested Livewire components shared with their standalone pages.
     */
    #[Url(as: 'tab')]
    public ?EmailPageTab $tab = null;

    #[Url(as: 'view')]
    public ?ConversationView $conversationView = null;

    #[Url(as: 'email')]
    public ?string $selectedEmailId = null;

    public ?string $conversationsCursor = null;

    public function mount(): void
    {
        if (! $this->showsConversations()) {
            $this->tab ??= EmailPageTab::DRAFTS;

            return;
        }

        if ($this->selectedEmailId !== null) {
            $this->openEmailReader($this->selectedEmailId);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [
            // The composer saves/discards drafts and queues mail from outside this
            // component, so the tab badges have to be told when those counts move.
            'drafts:changed' => 'refreshTabCounts',
            'outbox:changed' => 'refreshTabCounts',
        ];
    }

    public function refreshTabCounts(): void
    {
        unset($this->tabCounts);
    }

    public function setTab(string $tab): void
    {
        $this->tab = EmailPageTab::from($tab);
        $this->conversationView = null;
    }

    public function setView(string $view): void
    {
        if (! $this->showsConversations()) {
            return;
        }

        $this->conversationView = ConversationView::from($view);
        $this->tab = null;
        $this->conversationsCursor = null;
        unset($this->activeView, $this->conversations);
    }

    public function nextConversationsPage(): void
    {
        $this->conversationView ??= $this->activeView;
        $this->moveConversationsCursor($this->conversations->nextCursor);
    }

    public function previousConversationsPage(): void
    {
        $this->conversationView ??= $this->activeView;
        $this->moveConversationsCursor($this->conversations->previousCursor);
    }

    private function moveConversationsCursor(?string $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $this->conversationsCursor = $cursor;
        unset($this->conversations);
    }

    #[Computed]
    public function activeView(): ConversationView
    {
        if ($this->conversationView instanceof ConversationView) {
            return $this->conversationView;
        }

        return $this->viewCounts['needs_reply'] > 0 ? ConversationView::NeedsReply : ConversationView::All;
    }

    /** @return array{needs_reply: int, unlinked: int, automated: int} */
    #[Computed]
    public function viewCounts(): array
    {
        return resolve(ConversationsQuery::class)->counts($this->authUser());
    }

    #[Computed]
    public function showsConversations(): bool
    {
        $workspace = filament()->getTenant();

        return $this->conversationsEnabled($workspace instanceof Workspace ? $workspace : null);
    }

    #[Computed]
    public function conversations(): ConversationPage
    {
        return resolve(ConversationsQuery::class)->paginate(
            $this->authUser(),
            $this->activeView,
            new ConversationFilters,
            cursor: $this->conversationsCursor,
        );
    }

    #[Computed]
    public function selectedEmail(): ?Email
    {
        if ($this->selectedEmailId === null || ! $this->showsConversations()) {
            return null;
        }

        return $this->readableConversationCopy($this->selectedEmailId);
    }

    /** @return SupportCollection<int, EmailAccessRequest> */
    #[Computed]
    public function pendingAccessRequests(): SupportCollection
    {
        $email = $this->selectedEmail();

        if (! $email instanceof Email) {
            return collect();
        }

        return $this->pendingAccessRequestsFor($email);
    }

    public function selectEmail(string $id): void
    {
        $this->openEmailReader($id);
    }

    public function deselectEmail(): void
    {
        $this->selectedEmailId = null;
        unset($this->selectedEmail);

        $this->dispatch('composer:dismiss-inline');
    }

    #[Computed]
    public function hasConnectedMailbox(): bool
    {
        $workspace = filament()->getTenant();

        return ConnectedAccount::hasConnectedFor($this->authUser(), $workspace instanceof Workspace ? $workspace : null);
    }

    /** @return Collection<int, ConnectedAccount> */
    #[Computed]
    public function unhealthyMailboxes(): Collection
    {
        $workspace = filament()->getTenant();

        if (! $workspace instanceof Workspace) {
            return new Collection;
        }

        return ConnectedAccount::query()->ownedBy($this->authUser(), $workspace)->connected()->unhealthy()->with('workspace')->get();
    }

    public function connectMailboxAction(): ConnectMailboxAction
    {
        return ConnectMailboxAction::make();
    }

    public function connectAzureAction(): ConnectMailboxAction
    {
        return ConnectMailboxAction::microsoft();
    }

    /**
     * Badge counts for the tab bar. Drafts are the user's own unsent messages,
     * the outbox counts what its default Queued segment lists, failed counts delivery
     * failures, and templates count what the user may actually apply.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        $user = $this->authUser();
        $workspaceId = $user->current_workspace_id;

        $counts = DB::query()
            ->selectSub(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::DRAFT)
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::DRAFTS->value)
            ->selectSub(OutboxTab::QUEUED->constrain(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('direction', EmailDirection::OUTBOUND))
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::OUTBOX->value)
            ->selectSub(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::FAILED)
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::FAILED->value)
            ->selectSub(EmailTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where(fn (Builder $q): Builder => $q
                    ->where('is_shared', true)
                    ->orWhere('created_by', $user->getKey()))
                ->toBase()
                ->selectRaw('count(*)'), EmailPageTab::TEMPLATES->value)
            ->first();

        return array_map(intval(...), (array) $counts);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
