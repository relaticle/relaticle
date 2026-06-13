<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Billing\CreateProCheckout;
use App\Actions\Billing\StartProTrial;
use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Override;
use Relaticle\Chat\Models\AiCreditBalance;
use Throwable;

final class Billing extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $slug = 'billing';

    protected string $view = 'filament.pages.billing';

    #[Url]
    public ?string $checkout = null;

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
        try {
            $startProTrial->execute($this->user(), $this->team());
        } catch (AuthorizationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__('billing.trial.started'))->success()->send();
    }

    public function upgrade(CreateProCheckout $createCheckout, string $interval = 'monthly'): ?RedirectResponse
    {
        $team = $this->team();

        if (! $this->user()->ownsTeam($team) || $team->subscribed()) {
            return null;
        }

        try {
            return redirect()->away($createCheckout->execute($team, $interval));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();

            return null;
        }
    }

    public function managePortal(): ?RedirectResponse
    {
        $team = $this->team();

        if (! $this->user()->ownsTeam($team)) {
            return null;
        }

        try {
            return $team->redirectToBillingPortal(url("/app/{$team->slug}/billing"));
        } catch (Throwable $exception) {
            report($exception);
            $this->notifyCheckoutFailed();

            return null;
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
        $team = $this->team();
        $subscription = $team->subscription();

        return [
            'team' => $team,
            'isOwner' => $this->user()->ownsTeam($team),
            'subscription' => $subscription,
            'pastDue' => $subscription?->pastDue() ?? false,
            'onGrace' => $subscription?->onGracePeriod() ?? false,
            'trialAvailable' => $team->plan === Plan::Free
                && $this->user()->pro_trial_used_at === null
                && ! $team->subscriptions()->exists(),
            'balance' => AiCreditBalance::query()->where('team_id', $team->getKey())->first(),
            'activating' => $this->checkout === 'success' && ! $team->subscribed(),
        ];
    }

    private function team(): Team
    {
        /** @var Team */
        return Filament::getTenant();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
