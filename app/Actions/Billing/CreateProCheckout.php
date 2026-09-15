<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Filament\Pages\Billing;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CreateProCheckout
{
    /** @var list<string> */
    private const array INTERVALS = ['monthly', 'yearly'];

    /**
     * Stripe renders the card fields, so these only colour the frame around
     * them. Backgrounds match --surface-block-bg in resources/css/theme.css.
     *
     * @var array<string, array<string, string>>
     */
    private const array BRANDING = [
        'light' => ['background_color' => '#ffffff', 'button_color' => '#7c3aed', 'border_style' => 'rounded'],
        'dark' => ['background_color' => '#111827', 'button_color' => '#7c3aed', 'border_style' => 'rounded'],
    ];

    /**
     * Create the embedded Stripe Checkout session and return the client secret
     * the browser mounts it with. Stripe round-trip, covered by the staging E2E
     * checklist, not unit tests.
     */
    public function execute(Workspace $workspace, string $interval, string $theme = 'light'): string
    {
        $builder = $workspace
            ->newSubscription('default', $this->priceId($interval))
            ->allowPromotionCodes();

        $trialEndsAt = $workspace->onGenericTrial() ? $workspace->trial_ends_at : null;

        $builder = $trialEndsAt instanceof CarbonInterface
            ? $builder->trialUntil($trialEndsAt)
            : $builder->skipTrial();

        return (string) $builder
            ->checkout($this->sessionOptions($workspace, $theme))
            ->asStripeCheckoutSession()
            ->client_secret;
    }

    private function priceId(string $interval): string
    {
        // $interval arrives from the browser, so pin it to the known intervals and
        // an arbitrary string never reaches a config lookup.
        throw_unless(in_array($interval, self::INTERVALS, true), InvalidArgumentException::class, "Unsupported billing interval [{$interval}].");

        $priceId = config("services.stripe.prices.pro_{$interval}");

        throw_if(! is_string($priceId) || $priceId === '', InvalidArgumentException::class, "No Stripe price configured for interval [{$interval}].");

        return $priceId;
    }

    /** @return array<string, mixed> */
    private function sessionOptions(Workspace $workspace, string $theme): array
    {
        $billingUrl = Billing::getUrl(panel: 'app', tenant: $workspace);

        // Mandatory twice over: it is where a redirect-based method lands, and
        // Cashier otherwise defaults it to route('home'), which does not exist here.
        $options = [
            'ui_mode' => 'embedded',
            'redirect_on_completion' => 'if_required',
            'return_url' => "{$billingUrl}?checkout=success",
            'client_reference_id' => (string) $workspace->getKey(),
            'branding_settings' => self::BRANDING[$theme] ?? self::BRANDING['light'],
        ];

        if (config('services.stripe.managed_payments')) {
            $options['managed_payments'] = ['enabled' => true];
        }

        return $options;
    }
}
