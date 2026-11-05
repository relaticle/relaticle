<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use App\Models\Workspace;
use Filament\Pages\Page;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final class EmailInboxPage extends Page
{
    use HasEmailFeatureFlag;

    protected string $view = 'email-integration::filament.pages.email-inbox';

    protected static ?string $navigationLabel = null;

    protected static ?string $slug = 'email';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('filament/pages/email-inbox.navigation_label');
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
    public EmailPageTab $tab = EmailPageTab::DRAFTS;

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
    }

    #[Computed]
    public function hasConnectedMailbox(): bool
    {
        $workspace = filament()->getTenant();

        return ConnectedAccount::hasConnectedFor($this->authUser(), $workspace instanceof Workspace ? $workspace : null);
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

        return [
            EmailPageTab::DRAFTS->value => Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::DRAFT)
                ->count(),
            EmailPageTab::OUTBOX->value => OutboxTab::QUEUED->constrain(Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('direction', EmailDirection::OUTBOUND))
                ->count(),
            EmailPageTab::FAILED->value => Email::query()
                ->forWorkspace($workspaceId)
                ->where('user_id', $user->getKey())
                ->where('status', EmailStatus::FAILED)
                ->count(),
            EmailPageTab::TEMPLATES->value => EmailTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where(fn (Builder $q): Builder => $q
                    ->where('is_shared', true)
                    ->orWhere('created_by', $user->getKey()))
                ->count(),
        ];
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }
}
