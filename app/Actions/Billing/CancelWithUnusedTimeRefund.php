<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Invoice;
use Stripe\StripeObject;
use UnexpectedValueException;

final readonly class CancelWithUnusedTimeRefund
{
    public function __construct(private VoidOpenInvoices $voidOpenInvoices) {}

    public function execute(Subscription $subscription, string $idempotencyKey, CarbonImmutable $asOf): int
    {
        $invoice = Cashier::stripe()->invoices->all([
            'subscription' => $subscription->stripe_id,
            'status' => 'paid',
            'limit' => 1,
        ])->data[0] ?? null;

        $refunded = $invoice instanceof Invoice
            ? $this->refundUnusedTime($subscription, $invoice, "{$idempotencyKey}-{$invoice->id}", $asOf)
            : 0;

        $this->voidOpenInvoices->execute($subscription);

        $subscription->noProrate()->cancelNow();

        return $refunded;
    }

    private function refundUnusedTime(Subscription $subscription, Invoice $invoice, string $key, CarbonImmutable $asOf): int
    {
        try {
            return $this->refund($invoice, $key, $asOf);
        } catch (RateLimitException|StripeUnexpectedValueException $exception) {
            throw $exception;
        } catch (InvalidRequestException|UnexpectedValueException $exception) {
            $owed = rescue(fn (): int => $this->unusedAmount($invoice, $asOf), report: false);

            report(new UnexpectedValueException(
                "Switch refund skipped for invoice {$invoice->id} of subscription {$subscription->stripe_id} in workspace {$subscription->getAttribute($subscription->owner()->getForeignKeyName())}, owed ".($owed ?? 'unknown')." cents: {$exception->getMessage()}",
                previous: $exception,
            ));

            return 0;
        }
    }

    private function refund(Invoice $invoice, string $key, CarbonImmutable $asOf): int
    {
        $amount = $this->unusedAmount($invoice, $asOf);

        if ($amount === 0) {
            return 0;
        }

        $paymentIntent = $this->paymentIntentOf($invoice);
        $existing = $this->refundWithKey($paymentIntent, $key);

        if ($existing instanceof StripeObject) {
            return (int) $existing['amount'];
        }

        Cashier::stripe()->refunds->create([
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'metadata' => ['switch_key' => $key],
        ], ['idempotency_key' => $key]);

        return $amount;
    }

    private function unusedAmount(Invoice $invoice, CarbonImmutable $asOf): int
    {
        $paid = (int) $invoice->amount_paid;

        if ($paid === 0) {
            return 0;
        }

        ['start' => $start, 'end' => $end] = $this->subscriptionPeriod($invoice);

        return max(0, min($paid, intdiv($paid * ($end - $asOf->getTimestamp()), $end - $start)));
    }

    /** @return array{start: int, end: int} */
    private function subscriptionPeriod(Invoice $invoice): array
    {
        foreach ($invoice->lines->data as $line) {
            $isSubscriptionLine = ($line->parent->type ?? null) === 'subscription_item_details'
                && ! ($line->parent->subscription_item_details->proration ?? false);

            if ($isSubscriptionLine && $line->period['end'] > $line->period['start']) {
                return ['start' => (int) $line->period['start'], 'end' => (int) $line->period['end']];
            }
        }

        throw new UnexpectedValueException("Invoice {$invoice->id} has no subscription period to refund against.");
    }

    private function paymentIntentOf(Invoice $invoice): string
    {
        $payments = Cashier::stripe()->invoicePayments->all(['invoice' => $invoice->id, 'status' => 'paid']);
        $intent = $payments->data[0]->payment->payment_intent ?? null;

        if (! is_string($intent)) {
            throw new UnexpectedValueException("Invoice {$invoice->id} was not paid by a payment intent.");
        }

        return $intent;
    }

    private function refundWithKey(string $paymentIntent, string $key): ?StripeObject
    {
        $refunds = Cashier::stripe()->refunds->all(['payment_intent' => $paymentIntent, 'limit' => 100]);

        foreach ($refunds->data as $refund) {
            if (($refund->metadata['switch_key'] ?? null) === $key) {
                return $refund;
            }
        }

        return null;
    }
}
