<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Billing\CreateCreditPackCheckout;
use App\Actions\Billing\StartProTrial;
use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Enums\WorkspaceCapability;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Jobs\RefreshManagedRenewal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\HostedWorkspaceAccess;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Throwable;

final class Billing extends Page
{
    use HasWorkspaceSettingsNavigation;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.pages.billing';

    public const string CHECKOUT_SUCCESS = 'success';

    public const string CHECKOUT_REOPENED = 'reopened';

    #[Url]
    public ?string $checkout = null;

    #[Url]
    public ?string $credits = null;

    #[Override]
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getLabel(): string
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
        return $this->isPaused() ? 'filament.pages.billing-paused' : parent::getView();
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
            Notification::make()->title(__('billing.trial.not_available'))->danger()->send();

            return;
        }

        try {
            $started = $startProTrial->execute($this->user(), $this->workspace());
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (! $started) {
            Notification::make()->title(__('billing.trial.not_available'))->danger()->send();

            return;
        }

        Notification::make()->title(__('billing.trial.started'))->success()->send();
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

    public function buyCredits(CreateCreditPackCheckout $createCheckout, string $pack): void
    {
        $workspace = $this->workspace();

        if (! $this->user()->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage) || ! resolve(HostedWorkspaceAccess::class)->allows($workspace)) {
            return;
        }

        try {
            $this->redirect($createCheckout->execute($workspace, $pack));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    private function notifyCheckoutFailed(): void
    {
        Notification::make()
            ->title(__('billing.errors.checkout_failed'))
            ->danger()
            ->send();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $workspace = $this->workspace();
        $subscription = $workspace->subscription();
        $hasHostedAccess = resolve(HostedWorkspaceAccess::class)->allows($workspace);
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
            'renewalLabel' => $this->renewalLabel($managedSubscription),
            'pastDue' => $workspace->billingStatus() === BillingStatus::PastDue,
            'onGrace' => $subscription?->onGracePeriod() ?? false,
            'trialAvailable' => $this->trialAvailable(),
            'onStripeTrial' => $subscription?->onTrial() ?? false,
            'isGrandfathered' => $isGrandfathered,
            'balance' => AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first(),
            'activating' => in_array($this->checkout, [self::CHECKOUT_SUCCESS, self::CHECKOUT_REOPENED], true) && ! $workspace->subscribed() && ! $workspace->plan->isManaged(),
            'creditsFulfilling' => $this->credits === 'success',
            'availablePacks' => resolve(CreditPackCatalog::class)->purchasable(),
            ...($hasHostedAccess ? [] : $this->pausedViewData($workspace)),
        ];
    }

    private function renewalLabel(?Subscription $managedSubscription): ?string
    {
        if (! $managedSubscription instanceof Subscription || ! $managedSubscription->active() || $managedSubscription->pastDue() || $managedSubscription->onGracePeriod()) {
            return null;
        }

        $renewal = Cache::get(RefreshManagedRenewal::CACHE_PREFIX.$managedSubscription->stripe_id);

        if (! is_array($renewal)) {
            dispatch(new RefreshManagedRenewal($managedSubscription->stripe_id));

            return null;
        }

        return __('billing.managed.renews', [
            'date' => Date::createFromTimestamp($renewal['renews_at'])->toFormattedDateString(),
            'amount' => Number::currency($renewal['amount'] / 100, strtoupper($renewal['currency']), precision: $renewal['amount'] % 100 === 0 ? 0 : 2),
        ]);
    }

    /** @return array{billingStatus: BillingStatus, lapsedPlan: ?Plan, mailboxSyncPaused: bool, otherWorkspaces: Collection<int, Workspace>} */
    private function pausedViewData(Workspace $workspace): array
    {
        $billingStatus = $workspace->billingStatus();

        return [
            'billingStatus' => $billingStatus,
            'lapsedPlan' => $billingStatus === BillingStatus::SubscriptionEnded ? $workspace->lapsedManagedPlan() : null,
            'mailboxSyncPaused' => $workspace->hasConnectedMailbox(),
            'otherWorkspaces' => $this->user()->allWorkspaces()
                ->reject(fn (Workspace $other): bool => $other->is($workspace))
                ->values(),
        ];
    }

    private function isPaused(): bool
    {
        return resolve(HostedWorkspaceAccess::class)->isPaused($this->workspace());
    }

    /**
     * A manual trial start exists for grandfathered pre-billing workspaces,
     * which never received the automatic creation-time trial.
     */
    private function trialAvailable(): bool
    {
        $workspace = $this->workspace();

        return ! $this->isPaused()
            && $workspace->plan === Plan::Free
            && $workspace->pro_trial_used_at === null
            && ! $workspace->subscriptions()->exists();
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
