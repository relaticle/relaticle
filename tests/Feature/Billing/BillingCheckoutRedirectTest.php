<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

mutates(Billing::class);

/**
 * Stripe's SDK talks HTTP directly, so the only way to exercise the real
 * checkout path offline is to hand it a client that answers with a canned
 * session. Everything above it — Cashier, the action, the Livewire method —
 * runs for real.
 */
function fakeStripeCheckoutSession(string $url): void
{
    ApiRequestor::setHttpClient(new class($url) implements ClientInterface
    {
        public function __construct(private readonly string $url) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $body = match (true) {
                str_contains((string) $absUrl, '/checkout/sessions') => [
                    'id' => 'cs_test_fake',
                    'object' => 'checkout.session',
                    'url' => $this->url,
                ],
                str_contains((string) $absUrl, '/billing_portal/sessions') => [
                    'id' => 'bps_test_fake',
                    'object' => 'billing_portal.session',
                    'url' => $this->url,
                ],
                str_contains((string) $absUrl, '/customers') => [
                    'id' => 'cus_test_fake',
                    'object' => 'customer',
                    'email' => 'billing@example.test',
                ],
                default => ['id' => 'obj_test_fake', 'object' => 'object'],
            };

            return [json_encode($body), 200, []];
        }
    });
}

beforeEach(function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.credit_packs.small', ['price' => 'price_credits_1k_test', 'credits' => 1000]);

    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;
    $team->forceFill([
        'hosted_free_grandfathered_at' => now(),
        'plan' => Plan::Free,
        'stripe_id' => 'cus_test_fake',
    ])->save();

    $this->actingAs($user);
    Filament::setTenant($team);
    $this->team = $team;
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

it('redirects the owner to the Stripe checkout url when upgrading', function (): void {
    fakeStripeCheckoutSession('https://checkout.stripe.com/c/pay/cs_test_fake');

    livewire(Billing::class)
        ->call('upgrade', 'monthly')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_fake')
        ->assertNotNotified();
});

it('redirects the owner to the Stripe checkout url when buying a credit pack', function (): void {
    fakeStripeCheckoutSession('https://checkout.stripe.com/c/pay/cs_test_credits');

    livewire(Billing::class)
        ->call('buyCredits', 'small')
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_credits')
        ->assertNotNotified();
});

it('redirects the owner to the Stripe billing portal', function (): void {
    fakeStripeCheckoutSession('https://billing.stripe.com/p/session/test');
    $this->team->forceFill(['plan' => Plan::Pro])->save();

    livewire(Billing::class)
        ->call('managePortal')
        ->assertRedirect('https://billing.stripe.com/p/session/test')
        ->assertNotNotified();
});
