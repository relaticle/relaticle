<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Jobs;

use App\Enums\StripeSubscriptionStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Relaticle\Cloud\Billing\Data\Renewal;
use Stripe\Invoice;
use Stripe\PaymentMethod;
use Stripe\Subscription;

#[Tries(3)]
#[Backoff([10, 60])]
#[UniqueFor(300)]
final class RefreshRenewal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly string $stripeSubscriptionId) {}

    public function uniqueId(): string
    {
        return $this->stripeSubscriptionId;
    }

    public function handle(): void
    {
        $key = Renewal::cacheKey($this->stripeSubscriptionId);
        $subscription = Cashier::stripe()->subscriptions->retrieve($this->stripeSubscriptionId, [
            'expand' => ['default_payment_method', 'latest_invoice', 'customer.invoice_settings.default_payment_method'],
        ]);
        $renewsAt = $subscription->items->data[0]->current_period_end ?? null;

        if (! is_int($renewsAt) || ! in_array($subscription->status, StripeSubscriptionStatus::live(), true)) {
            Cache::forget($key);

            return;
        }

        $upcoming = Cashier::stripe()->invoices->createPreview($this->previewParams($subscription));
        $card = $this->card($subscription);

        Cache::forever($key, [
            'renews_at' => $renewsAt,
            'amount' => (int) $upcoming->total,
            'amount_due' => $this->amountDue($subscription),
            'currency' => (string) $upcoming->currency,
            'card_brand' => $card['brand'],
            'card_last4' => $card['last4'],
        ]);
    }

    // Stripe has no upcoming invoice for a subscription set to cancel, so it is priced as if kept.
    /** @return array<string, mixed> */
    private function previewParams(Subscription $subscription): array
    {
        $cancelling = $subscription->cancel_at_period_end === true || $subscription->cancel_at !== null;

        return ['subscription' => $subscription->id] + ($cancelling ? ['subscription_details' => ['cancel_at_period_end' => false]] : []);
    }

    // A past-due preview prices the next cycle; the open invoice holds what is owed now.
    private function amountDue(Subscription $subscription): ?int
    {
        $invoice = $subscription->latest_invoice;

        return $subscription->status === 'past_due' && $invoice instanceof Invoice && $invoice->status === 'open'
            ? (int) $invoice->amount_due
            : null;
    }

    // Checkout keeps the card on the subscription; the customer's default is the fallback.
    /** @return array{brand: string|null, last4: string|null} */
    private function card(Subscription $subscription): array
    {
        $method = $subscription->default_payment_method;

        if (! $method instanceof PaymentMethod) {
            $method = $subscription->customer->invoice_settings->default_payment_method ?? null;
        }

        $card = $method instanceof PaymentMethod ? $method->card : null;

        return [
            'brand' => is_string($card?->brand) ? $card->brand : null,
            'last4' => is_string($card?->last4) ? $card->last4 : null,
        ];
    }
}
