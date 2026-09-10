<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\NotifyWorkspaceOfPaymentFailure;
use App\Actions\Billing\RestoreWorkspaceTrial;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

final class StripeWebhookController extends CashierWebhookController
{
    /**
     * Stripe statuses a subscription can be created in without ever granting
     * access: a checkout whose first payment failed or was abandoned.
     *
     * @var list<string>
     */
    private const array NON_GRANTING_STATUSES = ['incomplete', 'incomplete_expired'];

    /**
     * Why an invoice was raised, for the failures worth alarming a workspace
     * about: a renewal, and a plan change billed immediately. Both belong to a
     * subscription the workspace already has, which is what the notification's
     * wording assumes.
     *
     * @var list<string>
     */
    private const array ALARMING_BILLING_REASONS = ['subscription_cycle', 'subscription_update'];

    public function __construct(
        private readonly RestoreWorkspaceTrial $restoreTrial,
        private readonly GrantPurchasedCredits $grantCredits,
        private readonly NotifyWorkspaceOfPaymentFailure $notifyPaymentFailure,
    ) {
        parent::__construct();
    }

    /**
     * Cashier clears the billable's generic trial for every created
     * subscription, including one that never charged. Restore it so an
     * abandoned checkout leaves the workspace's running trial intact.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        /** @var array<string, mixed> $object */
        $object = $payload['data']['object'] ?? [];

        if (! in_array($object['status'] ?? null, self::NON_GRANTING_STATUSES, true)) {
            return parent::handleCustomerSubscriptionCreated($payload);
        }

        $stripeId = $object['customer'] ?? null;
        $team = is_string($stripeId) ? Team::query()->where('stripe_id', $stripeId)->first() : null;
        $trialEndsAt = $team?->trial_ends_at;

        $response = parent::handleCustomerSubscriptionCreated($payload);

        if ($team instanceof Team && $trialEndsAt !== null) {
            $this->restoreTrial->execute($team, $trialEndsAt);
        }

        return $response;
    }

    /**
     * Cashier ships no handler for this event, so WebhookHandled never fires for
     * it and a listener on that event could never see it.
     *
     * The subscription lives at parent.subscription_details on the current API
     * version, which dropped the top-level `subscription` key. Reading the old
     * key would match nothing and silently notify no one. A credit-pack invoice
     * has no such parent and must not raise a subscription alarm.
     *
     * Stripe repeats this event for every retry of the same invoice, eight over
     * two weeks under the default Smart Retries policy, so only the first
     * attempt is news. A missing count is read as the first, because failing
     * toward one extra alarm beats failing toward silence.
     *
     * The notification tells the owner to update their card "to keep Pro", so
     * it only fits a subscription that is already running. A failed first
     * charge is `subscription_create`, where nothing was ever kept and Stripe
     * expires the attempt instead of retrying it; the checkout flow reports
     * that one synchronously.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        /** @var array<string, mixed> $object */
        $object = $payload['data']['object'] ?? [];
        $customer = $object['customer'] ?? null;

        if (($object['parent']['type'] ?? null) !== 'subscription_details' || ! is_string($customer)) {
            return $this->successMethod();
        }

        if (! in_array($object['billing_reason'] ?? null, self::ALARMING_BILLING_REASONS, true)) {
            return $this->successMethod();
        }

        if (($object['attempt_count'] ?? 1) !== 1) {
            return $this->successMethod();
        }

        $team = Team::query()->where('stripe_id', $customer)->first();

        if ($team instanceof Team) {
            $this->notifyPaymentFailure->execute($team);
        }

        return $this->successMethod();
    }

    /**
     * Fulfill credit-pack purchases for a checkout that settled synchronously.
     * Delayed-notification payment methods complete with payment_status
     * 'unpaid' and settle later via checkout.session.async_payment_succeeded, so
     * fulfilling here without the gate would grant credits before the money
     * arrives, with nothing to reverse them if payment ultimately fails.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        /** @var array<string, mixed> $session */
        $session = $payload['data']['object'] ?? [];

        if (($session['payment_status'] ?? null) !== 'paid') {
            return $this->successMethod();
        }

        return $this->fulfillCreditPackCheckout($session);
    }

    /**
     * Fulfill a credit-pack purchase whose payment settled asynchronously after
     * an initial 'unpaid' checkout.session.completed. Reuses the same
     * "pack-{sessionId}" idempotency key as the completed handler, so a session
     * that both completes as paid and later confirms async grants exactly once.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        /** @var array<string, mixed> $session */
        $session = $payload['data']['object'] ?? [];

        return $this->fulfillCreditPackCheckout($session);
    }

    /**
     * Fulfill credit-pack purchases for a settled (payment_status=paid)
     * checkout session. Subscription checkouts emit the same events and are
     * ignored here (mode filter); customer.subscription.* handles them.
     *
     * @param  array<string, mixed>  $session
     */
    private function fulfillCreditPackCheckout(array $session): Response
    {
        if (($session['mode'] ?? null) !== 'payment') {
            return $this->successMethod();
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $priceId = $metadata['credit_pack_price'] ?? null;
        $teamId = $metadata['team_id'] ?? null;
        $sessionId = $session['id'] ?? null;
        $customerId = $session['customer'] ?? null;

        if (! is_string($priceId) || ! is_string($teamId) || ! is_string($sessionId)) {
            Log::warning('Credit pack checkout ignored: missing or malformed metadata', [
                'session_id' => is_string($sessionId) ? $sessionId : null,
                'missing_fields' => array_keys(array_filter([
                    'metadata.team_id' => ! is_string($teamId),
                    'metadata.credit_pack_price' => ! is_string($priceId),
                    'session.id' => ! is_string($sessionId),
                ])),
            ]);

            return $this->successMethod();
        }

        $team = Team::query()->find($teamId);

        if (! $team instanceof Team || ! is_string($customerId) || $team->stripe_id !== $customerId) {
            Log::warning('Credit pack checkout ignored: team/customer mismatch', [
                'team_id' => $teamId,
                'customer' => $customerId,
                'expected_customer' => $team?->stripe_id,
                'session_id' => $sessionId,
            ]);

            return $this->successMethod();
        }

        $this->grantCredits->execute($team, $priceId, $sessionId);

        return $this->successMethod();
    }
}
