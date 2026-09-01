<?php

declare(strict_types=1);

namespace Database\Seeders\Personas;

use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;

/**
 * Real subscriptions in the Stripe test sandbox, not hand-written rows.
 *
 * A locally invented `subscriptions` row renders the right badge and then lies
 * about everything else: "Open in Stripe" 404s, the invoice list is empty, and
 * the billing portal has nothing to show. Going through Cashier means the
 * workspace bills against a real customer, so a browser walk exercises the same
 * path production does.
 *
 * This costs network on every seed: roughly 7s per subscription, and the
 * past-due persona needs a test clock advanced past a renewal, which takes
 * about a minute.
 */
final readonly class StripeSandbox
{
    /**
     * Stripe's own test payment method: succeeds on the first charge, then
     * declines every renewal. That decline is what produces `past_due`.
     */
    private const string DECLINING_CARD = 'pm_card_chargeCustomerFail';

    private const int CLOCK_TIMEOUT_SECONDS = 180;

    public function __construct(private ?Command $command = null) {}

    /**
     * Give the workspace a live subscription, replacing whatever it had.
     */
    public function subscribe(Team $team, Persona $persona): void
    {
        $this->assertTestMode();
        $this->reset($team);

        $price = (string) config('services.stripe.prices.pro_monthly');

        if ($persona->pastDue) {
            $this->subscribeThenFailRenewal($team, $price, $persona);

            return;
        }

        $team->newSubscription('default', $price)->create(
            $persona->stripe,
            $this->customerOptions($persona),
        );
    }

    /**
     * A Team carries no email, so Cashier would create a nameless customer that
     * is impossible to identify in the Stripe dashboard. Naming it after the
     * persona is what makes a sandbox customer recognisable at a glance.
     *
     * @return array<string, string>
     */
    private function customerOptions(Persona $persona): array
    {
        return [
            'email' => $persona->email,
            'name' => $persona->workspace,
            'description' => "Local persona: {$persona->slug}",
        ];
    }

    /**
     * Subscribe on a test clock, swap in a card that declines, then advance past
     * the renewal so Stripe itself moves the subscription to `past_due`.
     */
    private function subscribeThenFailRenewal(Team $team, string $price, Persona $persona): void
    {
        $stripe = Cashier::stripe();

        $clock = $stripe->testHelpers->testClocks->create([
            'frozen_time' => now()->timestamp,
            'name' => "local-{$team->slug}",
        ]);

        $team->createAsStripeCustomer([...$this->customerOptions($persona), 'test_clock' => $clock->id]);
        $subscription = $team->newSubscription('default', $price)->create('pm_card_visa');

        $team->updateDefaultPaymentMethod(self::DECLINING_CARD);

        $renewal = $stripe->subscriptions->retrieve($subscription->stripe_id);
        $periodEnd = $renewal->items->data[0]->current_period_end ?? null;

        throw_unless(is_int($periodEnd), RuntimeException::class, 'Stripe returned no current_period_end to advance past.');

        $this->command?->info('  Advancing the Stripe test clock past renewal (up to 3 minutes)...');

        $stripe->testHelpers->testClocks->advance($clock->id, ['frozen_time' => $periodEnd + 3600]);

        $this->awaitClock($clock->id);

        $subscription->syncStripeStatus();
    }

    /**
     * Stripe advances a clock asynchronously and bills while it does, so the
     * subscription's new status is only trustworthy once the clock is `ready`.
     */
    private function awaitClock(string $clockId): void
    {
        $stripe = Cashier::stripe();
        $deadline = now()->addSeconds(self::CLOCK_TIMEOUT_SECONDS);

        do {
            Sleep::sleep(5);
            $status = $stripe->testHelpers->testClocks->retrieve($clockId)->status;

            if ($status === 'ready') {
                return;
            }
        } while (now()->lessThan($deadline));

        throw new RuntimeException("Stripe test clock {$clockId} did not settle within ".self::CLOCK_TIMEOUT_SECONDS.'s (status: '.$status.').');
    }

    /**
     * Detach the workspace from any sandbox customer a previous run left behind,
     * so re-seeding does not strand billable objects in Stripe.
     */
    private function reset(Team $team): void
    {
        if (! $team->hasStripeId()) {
            return;
        }

        try {
            Cashier::stripe()->customers->delete($team->stripe_id);
        } catch (InvalidRequestException) {
            // Already gone from Stripe; the local row below is the only cleanup left.
        }

        Subscription::query()->where('team_id', $team->getKey())->delete();
        $team->forceFill(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null])->save();
    }

    /**
     * Whether this checkout can bill the sandbox at all. A clone with no Stripe
     * keys is the normal case, not an error: the seeder skips these personas
     * rather than aborting the whole local seed over a credential it does not
     * need for the other four.
     */
    public function available(): bool
    {
        return str_contains((string) config('cashier.secret'), '_test_');
    }

    /**
     * A live key here would create real customers and real charges.
     */
    private function assertTestMode(): void
    {
        throw_unless(
            $this->available(),
            RuntimeException::class,
            'Refusing to seed: STRIPE_SECRET is not a test-mode key.',
        );
    }
}
