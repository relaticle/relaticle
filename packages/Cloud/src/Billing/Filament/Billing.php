<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Filament;

use App\Contracts\Billing as BillingContract;
use App\Contracts\Entitlements;
use App\Data\SubscriptionEnding;
use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Cloud\Billing\Actions\CreateCreditPackCheckout;
use Relaticle\Cloud\Billing\Actions\ExtendEndedTrial;
use Relaticle\Cloud\Billing\Actions\GrantPurchasedCredits;
use Relaticle\Cloud\Billing\Actions\KeepWorkspaceSubscription;
use Relaticle\Cloud\Billing\Actions\StartProTrial;
use Relaticle\Cloud\Billing\CreditPackCatalog;
use Relaticle\Cloud\Billing\Data\BillingSummary;
use Relaticle\Cloud\Billing\Jobs\RefreshRenewal;
use Relaticle\Cloud\Billing\Livewire\Concerns\StartsSubscriptionCheckout;
use Relaticle\Cloud\Billing\Queries\BillingSummaryQuery;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;
use Throwable;

final class Billing extends Page
{
    use HasWorkspaceSettingsNavigation;
    use StartsSubscriptionCheckout;

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-credit-card';

    protected static ?string $slug = self::BILLING_SLUG;

    protected string $view = 'cloud::filament.pages.billing';

    public const string CHECKOUT_SUCCESS = 'success';

    public const string CHECKOUT_REOPENED = 'reopened';

    public const string KEEP_SUBSCRIPTION_ACTION = 'keepSubscription';

    public const string OVERVIEW_TAB = 'overview';

    public const string USAGE_TAB = 'usage';

    private const string CHECKOUT_SESSION_PREFIX = 'cs_';

    #[Url]
    public ?string $checkout = null;

    #[Url]
    public ?string $credits = null;

    #[Url]
    public string $tab = self::OVERVIEW_TAB;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getLabel(): string
    {
        return __('billing.title');
    }

    public function getTitle(): string
    {
        return __('billing.title');
    }

    public function mount(): void
    {
        abort_unless(Feature::active(BillingFeature::class), 403);

        if ($this->checkout === self::CHECKOUT_REOPENED && ! $this->isPaused()) {
            $this->redirect(Filament::getUrl($this->workspace()));
        }
    }

    #[Override]
    public function getLayout(): string
    {
        return $this->isPaused() ? 'filament-panels::components.layout.base' : parent::getLayout();
    }

    #[Override]
    public function getView(): string
    {
        return $this->isPaused() ? 'cloud::filament.pages.billing-paused' : parent::getView();
    }

    public function reopenWhenActive(): void
    {
        if ($this->isPaused()) {
            return;
        }

        $this->skipRender();
        $this->redirect(Filament::getUrl($this->workspace()));
    }

    public function startTrial(StartProTrial $startProTrial): void
    {
        // The button is only rendered for an eligible workspace, but the
        // Livewire method is reachable regardless, so enforce it server-side.
        if (! $this->trialAvailable()) {
            Notification::make()->title(__('cloud::billing.trial.not_available'))->danger()->send();

            return;
        }

        try {
            $started = $startProTrial->execute($this->user(), $this->workspace());
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (! $started) {
            Notification::make()->title(__('cloud::billing.trial.not_available'))->danger()->send();

            return;
        }

        Notification::make()->title(__('cloud::billing.trial.started'))->success()->send();
    }

    public function extendTrial(ExtendEndedTrial $extendEndedTrial): void
    {
        try {
            $extended = $extendEndedTrial->execute($this->user(), $this->workspace());
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (! $extended) {
            Notification::make()->title(__('cloud::billing.trial.not_available'))->danger()->send();

            return;
        }

        Notification::make()
            ->title(__('cloud::billing.paused.extended.title'))
            ->body(__('cloud::billing.paused.extended.body', ['days' => Workspace::TRIAL_EXTENSION_DAYS]))
            ->success()
            ->send();

        $this->redirect(self::getUrl());
    }

    public function managePortal(): void
    {
        $workspace = $this->workspace();

        if (! $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage)) {
            return;
        }

        try {
            $configuration = $workspace->plan->isManaged() ? config('services.stripe.portal_configurations.managed') : null;

            $this->redirect($workspace->billingPortalUrl(
                self::getUrl(panel: 'app', tenant: $workspace),
                $configuration === null ? [] : ['configuration' => $configuration],
            ));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    public function keepSubscriptionAction(): Action
    {
        return Action::make(self::KEEP_SUBSCRIPTION_ACTION)
            ->label(fn (): string => $this->subscriptionEnding()->keepLabel ?? '')
            ->visible(fn (): bool => $this->subscriptionEnding() instanceof SubscriptionEnding
                && $this->user()->hasWorkspaceCapability($this->workspace()->getKey(), WorkspaceCapability::BillingManage))
            ->requiresConfirmation()
            ->modalIcon('lucide-rotate-ccw')
            ->modalIconColor('primary')
            ->modalHeading(fn (): string => $this->subscriptionEnding()->heading ?? '')
            ->modalDescription(fn (): string => $this->subscriptionEnding()->terms ?? '')
            ->modalSubmitActionLabel(fn (): string => $this->subscriptionEnding()->keepLabel ?? '')
            ->modalSubmitAction(fn (Action $action): Action => $action->color('primary'))
            ->modalCancelActionLabel(__('cloud::billing.keep.dismiss'))
            ->action(fn () => $this->keepSubscription());
    }

    private function keepSubscription(): void
    {
        $ending = $this->subscriptionEnding();

        try {
            $kept = resolve(KeepWorkspaceSubscription::class)->execute($this->user(), $this->workspace());
        } catch (AuthorizationException) {
            return;
        } catch (Throwable $exception) {
            report($exception);
            $kept = false;
        }

        if (! $kept || ! $ending instanceof SubscriptionEnding) {
            Notification::make()->title(__('cloud::billing.keep.failed'))->danger()->send();

            return;
        }

        Notification::make()->title($ending->keptMessage)->success()->send();
    }

    private function subscriptionEnding(): ?SubscriptionEnding
    {
        return resolve(BillingContract::class)->subscriptionEnding($this->workspace());
    }

    public function buyCredits(CreateCreditPackCheckout $createCheckout, string $pack): void
    {
        $workspace = $this->workspace();

        if (! $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage) || ! resolve(Entitlements::class)->allowsAccess($workspace)) {
            return;
        }

        try {
            $this->redirect($createCheckout->execute($workspace, $pack));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $workspace = $this->workspace();
        $subscription = $workspace->subscription();
        $hasHostedAccess = resolve(Entitlements::class)->allowsAccess($workspace);
        $isGrandfathered = $workspace->hosted_free_grandfathered_at !== null;
        $managedSubscription = $workspace->managedSubscription();

        return [
            'workspace' => $workspace,
            // Not $workspace->plan->credits(): a past-due workspace refills at the Free
            // allowance, so the plan's figure would name credits it never gets.
            'allowance' => resolve(CreditService::class)->allowanceFor($workspace),
            'canManageBilling' => $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
            'subscription' => $subscription,
            'managedSubscription' => $managedSubscription,
            'managedPastDue' => $managedSubscription?->pastDue() ?? false,
            'summary' => $this->summary($workspace),
            'pastDue' => $workspace->billingStatus() === BillingStatus::PastDue,
            'onGrace' => $subscription?->onGracePeriod() ?? false,
            'trialAvailable' => $this->trialAvailable(),
            'onStripeTrial' => $subscription?->onTrial() ?? false,
            'isGrandfathered' => $isGrandfathered,
            'balance' => AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first(),
            'activating' => in_array($this->checkout, [self::CHECKOUT_SUCCESS, self::CHECKOUT_REOPENED], true) && ! $workspace->subscribed() && ! $workspace->plan->isManaged(),
            'creditsFulfilling' => $this->creditsFulfilling($workspace),
            'availablePacks' => resolve(CreditPackCatalog::class)->purchasable(),
            'showsUsage' => $this->tab === self::USAGE_TAB && $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
            ...($hasHostedAccess ? [] : $this->pausedViewData($workspace)),
        ];
    }

    private function summary(Workspace $workspace): BillingSummary
    {
        $summary = resolve(BillingSummaryQuery::class)->forWorkspace(
            $workspace,
            $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage),
        );

        if ($summary->refreshSubscriptionId !== null) {
            dispatch(new RefreshRenewal($summary->refreshSubscriptionId));
        }

        return $summary;
    }

    private function creditsFulfilling(Workspace $workspace): bool
    {
        if ($this->credits === null || ! str_starts_with($this->credits, self::CHECKOUT_SESSION_PREFIX)) {
            return false;
        }

        return AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('idempotency_key', GrantPurchasedCredits::IDEMPOTENCY_PREFIX.$this->credits)
            ->doesntExist();
    }

    /** @return array{billingStatus: BillingStatus, deletesWhenInactive: bool, lapsedPlan: ?Plan, mailboxSyncPaused: bool, trialExtensionAvailable: bool, otherWorkspaces: Collection<int, Workspace>} */
    private function pausedViewData(Workspace $workspace): array
    {
        $billingStatus = $workspace->billingStatus();

        return [
            'billingStatus' => $billingStatus,
            'deletesWhenInactive' => ! $workspace->isScheduledForDeletion() && resolve(InactiveTrialWorkspacesQuery::class)->isEligible($workspace),
            'lapsedPlan' => $billingStatus === BillingStatus::SubscriptionEnded ? $workspace->lapsedManagedPlan() : null,
            'mailboxSyncPaused' => $workspace->hasConnectedMailbox(),
            'trialExtensionAvailable' => $workspace->canExtendTrial(),
            'otherWorkspaces' => $this->user()->allWorkspaces()
                ->reject(fn (Workspace $other): bool => $other->is($workspace))
                ->values(),
        ];
    }

    private function isPaused(): bool
    {
        return ! resolve(Entitlements::class)->allowsAccess($this->workspace());
    }

    /**
     * A manual trial start exists for grandfathered pre-billing workspaces,
     * which never received the automatic creation-time trial.
     */
    private function trialAvailable(): bool
    {
        return ! $this->isPaused() && $this->workspace()->canStartTrial();
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
