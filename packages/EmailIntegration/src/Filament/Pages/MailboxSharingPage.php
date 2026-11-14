<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Relaticle\EmailIntegration\Actions\SaveMailboxSharingTier;
use Relaticle\EmailIntegration\Data\ConversationFilters;
use Relaticle\EmailIntegration\Data\ConversationRow;
use Relaticle\EmailIntegration\Enums\ConversationView;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailFeatureFlag;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;
use Relaticle\EmailIntegration\Services\PrivacyService;

final class MailboxSharingPage extends Page
{
    use HasEmailFeatureFlag;

    private const int PREVIEW_ROWS = 3;

    protected string $view = 'email-integration::filament.pages.mailbox-sharing';

    protected static ?string $slug = 'email/sharing';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked, Url]
    public string $mailbox = '';

    #[Locked, Url]
    public ?string $return = null;

    public string $sharingTier = EmailPrivacyTier::METADATA_ONLY->value;

    private ?ConnectedAccount $mailboxRecord = null;

    public function mount(): void
    {
        $mailbox = $this->mailboxRecord();

        if ($mailbox->hasAnsweredSharing()) {
            $this->redirect($this->returnUrl());

            return;
        }

        $this->sharingTier = $this->preselectedTier($mailbox)->value;
    }

    public function getTitle(): string
    {
        return __('filament/pages/email-accounts.sharing_step.title');
    }

    public function save(): void
    {
        $this->validate(['sharingTier' => ['required', Rule::enum(EmailPrivacyTier::class)->only(EmailPrivacyTier::offeredAtConnect())]]);

        resolve(SaveMailboxSharingTier::class)->execute($this->user(), $this->mailboxRecord(), EmailPrivacyTier::from($this->sharingTier));

        $this->redirect($this->returnUrl());
    }

    public function mailboxRecord(): ConnectedAccount
    {
        return $this->mailboxRecord ??= ConnectedAccount::query()
            ->ownedBy($this->user(), $this->workspace())
            ->findOrFail($this->mailbox);
    }

    public function chosenTier(): EmailPrivacyTier
    {
        $tier = EmailPrivacyTier::tryFrom($this->sharingTier);

        return in_array($tier, EmailPrivacyTier::offeredAtConnect(), true) ? $tier : EmailPrivacyTier::METADATA_ONLY;
    }

    /**
     * @return list<ConversationRow>
     */
    public function previewRows(): array
    {
        $page = resolve(ConversationsQuery::class)->paginate(
            $this->user(),
            ConversationView::All,
            new ConversationFilters(mailboxIds: [(string) $this->mailboxRecord()->getKey()]),
            perPage: 20,
        );

        return array_slice(
            array_filter($page->rows, fn (ConversationRow $row): bool => $row->records !== []),
            0,
            self::PREVIEW_ROWS,
        );
    }

    public function moreOptionsUrl(): string
    {
        return EmailAccountSettingsPage::getUrl(['account' => $this->mailboxRecord()->getKey()]);
    }

    private function preselectedTier(ConnectedAccount $mailbox): EmailPrivacyTier
    {
        $effective = $mailbox->sharing_tier ?? resolve(PrivacyService::class)->workspaceSharingTier($this->workspace());

        return in_array($effective, EmailPrivacyTier::offeredAtConnect(), true) ? $effective : EmailPrivacyTier::METADATA_ONLY;
    }

    private function returnUrl(): string
    {
        $fallback = EmailAccountsPage::getUrl();

        if (! is_string($this->return) || $this->return === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $this->return)) {
            return $fallback;
        }

        if (Str::startsWith($this->return, '/') && ! Str::startsWith($this->return, ['//', '/\\'])) {
            return $this->return;
        }

        $host = parse_url($this->return, PHP_URL_HOST);
        $scheme = parse_url($this->return, PHP_URL_SCHEME);

        return is_string($host) && in_array($scheme, ['http', 'https'], true) && strcasecmp($host, (string) parse_url(url('/'), PHP_URL_HOST)) === 0
            ? $this->return
            : $fallback;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
