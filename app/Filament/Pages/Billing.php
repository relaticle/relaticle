<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Billing\CreateCreditPackCheckout;
use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Concerns\HasWorkspaceSettingsNavigation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\HostedWorkspaceAccess;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
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

        if (! $this->user()->ownsWorkspace($workspace)) {
            return;
        }

        try {
            $this->redirect($workspace->billingPortalUrl(self::getUrl(panel: 'app', tenant: $workspace)));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();
        }
    }

    public function buyCredits(CreateCreditPackCheckout $createCheckout, string $pack): void
    {
        $workspace = $this->workspace();

        if (! $this->user()->ownsWorkspace($workspace) || ! resolve(HostedWorkspaceAccess::class)->allows($workspace)) {
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

        return [
            'workspace' => $workspace,
            // Not $workspace->plan->credits(): a past-due workspace refills at the Free
            // allowance, so the plan's figure would name credits it never gets.
            'allowance' => resolve(CreditService::class)->allowanceFor($workspace),
            'isOwner' => $this->user()->ownsWorkspace($workspace),
            'subscription' => $subscription,
            'pastDue' => $subscription?->pastDue() ?? false,
            'onGrace' => $subscription?->onGracePeriod() ?? false,
            'trialAvailable' => $this->trialAvailable(),
            'onStripeTrial' => $subscription?->onTrial() ?? false,
            'hasHostedAccess' => $hasHostedAccess,
            'isGrandfathered' => $isGrandfathered,
            'balance' => AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first(),
            'activating' => $this->checkout === 'success' && ! $workspace->subscribed() && $workspace->plan !== Plan::Enterprise,
            'creditsFulfilling' => $this->credits === 'success',
            'availablePacks' => resolve(CreditPackCatalog::class)->purchasable(),
        ];
    }

    /**
     * A manual trial start is the escape hatch for a workspace that never
     * received its automatic creation-time trial: grandfathered pre-billing
     * workspaces and workspaces created while trials were per-user.
     */
    private function trialAvailable(): bool
    {
        $workspace = $this->workspace();

        return $workspace->plan === Plan::Free
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
