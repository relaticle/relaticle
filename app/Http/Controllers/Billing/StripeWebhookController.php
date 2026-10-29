<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ActivateManagedPlan;
use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\NotifyWorkspaceOfPaymentFailure;
use App\Actions\Billing\RestoreWorkspaceTrial;
use App\Actions\Billing\VoidOpenInvoices;
use App\Enums\StripeSubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Jobs\RefreshManagedRenewal;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ManagedInvoiceOverdueNotification;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Laravel\Cashier\Subscription;
use Stripe\Invoice;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class StripeWebhookController extends CashierWebhookController
{
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
        private readonly ActivateManagedPlan $activateManagedPlan,
        private readonly VoidOpenInvoices $voidOpenInvoices,
    ) {
        parent::__construct();
    }

    /**
     * Cashier clears the billable's generic trial for every created
     * subscription, including one that never charged. Restore it so an
     * abandoned checkout or an unpaid managed invoice leaves the workspace's
     * running trial intact.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        /** @var array<string, mixed> $object */
        $object = $payload['data']['object'] ?? [];

        $grantsNothingYet = in_array($object['status'] ?? null, StripeSubscriptionStatus::neverGranted(), true)
            || ($object['metadata']['type'] ?? null) === SubscriptionType::ManagedPending->value;

        if (! $grantsNothingYet) {
            return parent::handleCustomerSubscriptionCreated($payload);
        }

        $stripeId = $object['customer'] ?? null;
        $workspace = is_string($stripeId) ? Workspace::query()->where('stripe_id', $stripeId)->first() : null;
        $trialEndsAt = $workspace?->trial_ends_at;

        $response = parent::handleCustomerSubscriptionCreated($payload);

        if ($workspace instanceof Workspace && $trialEndsAt !== null) {
            $this->restoreTrial->execute($workspace, $trialEndsAt);
        }

        return $response;
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);

        $stripeId = $payload['data']['object']['id'] ?? null;
        $subscription = is_string($stripeId)
            ? Subscription::query()->where('stripe_id', $stripeId)->whereIn('type', SubscriptionType::managed())->first()
            : null;

        if ($subscription instanceof Subscription) {
            $this->voidOpenInvoices->execute($subscription);
        }

        return $response;
    }

    /** @param  array<string, mixed>  $payload */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $stripeId = $payload['data']['object']['id'] ?? null;

        // Stripe does not deliver events in order, so a late update would write an older
        // price over a newer one. Cashier maps the subscription as Stripe holds it now.
        if (is_string($stripeId)) {
            $payload['data']['object'] = Cashier::stripe()->subscriptions->retrieve($stripeId)->toArray();
        }

        $response = parent::handleCustomerSubscriptionUpdated($payload);

        $this->followManagedSubscription($payload);

        return $response;
    }

    /** @param  array<string, mixed>  $payload */
    private function followManagedSubscription(array $payload): void
    {
        /** @var array<string, mixed> $object */
        $object = $payload['data']['object'] ?? [];
        $stripeId = $object['id'] ?? null;

        $subscription = is_string($stripeId)
            ? Subscription::query()->where('stripe_id', $stripeId)->where('type', SubscriptionType::Managed->value)->first()
            : null;

        if (! $subscription instanceof Subscription) {
            return;
        }

        dispatch(new RefreshManagedRenewal($subscription->stripe_id));

        $previousStatus = $payload['data']['previous_attributes']['status'] ?? null;

        if (is_string($previousStatus) && $previousStatus !== 'past_due' && ($object['status'] ?? null) === 'past_due') {
            $this->notifyOwnerOfOverdueInvoice($subscription);
        }
    }

    private function notifyOwnerOfOverdueInvoice(Subscription $subscription): void
    {
        $workspace = $subscription->owner()->first();

        if (! $workspace instanceof Workspace || ! $workspace->planIsSoldBy($subscription)) {
            return;
        }

        $owner = $workspace->owner()->first();

        if ($owner instanceof User) {
            $owner->notify(new ManagedInvoiceOverdueNotification($workspace, $workspace->plan));
        }
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

        $workspace = Workspace::query()->where('stripe_id', $customer)->first();

        if ($workspace instanceof Workspace) {
            $this->notifyPaymentFailure->execute($workspace);
        }

        return $this->successMethod();
    }

    // Only a managed subscription's first invoice switches a plan on. A row already `managed`
    // is admitted so a redelivery can finish a switch that failed part way.
    /** @param  array<string, mixed>  $payload */
    protected function handleInvoicePaid(array $payload): Response
    {
        /** @var array<string, mixed> $invoice */
        $invoice = $payload['data']['object'] ?? [];
        $stripeSubscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;

        if (! is_string($stripeSubscriptionId)) {
            return $this->successMethod();
        }

        $subscription = Subscription::query()
            ->where('stripe_id', $stripeSubscriptionId)
            ->whereIn('type', SubscriptionType::managed())
            ->first();

        if ($subscription?->ended() === true) {
            $this->reportPaidAfterEnd($subscription, $invoice['id'] ?? null);

            return $this->successMethod();
        }

        if (($invoice['billing_reason'] ?? null) !== Invoice::BILLING_REASON_SUBSCRIPTION_CREATE) {
            return $this->successMethod();
        }

        $workspace = $subscription?->owner()->first();

        if (! $workspace instanceof Workspace) {
            return $this->successMethod();
        }

        $customer = $invoice['customer'] ?? null;
        $invoiceId = $invoice['id'] ?? null;

        if (! is_string($customer) || ! is_string($invoiceId) || $workspace->stripe_id !== $customer) {
            Log::warning('Managed plan invoice ignored: workspace/customer mismatch', [
                'workspace_id' => $workspace->getKey(),
                'customer' => $customer,
                'expected_customer' => $workspace->stripe_id,
                'invoice_id' => $invoiceId,
            ]);

            report(new UnexpectedValueException(
                "Managed invoice {$invoiceId} was paid by customer {$customer}, not workspace {$workspace->getKey()}'s; nothing was granted.",
            ));

            return $this->successMethod();
        }

        $this->activateManagedPlan->execute($workspace, $stripeSubscriptionId, $invoiceId);

        return $this->successMethod();
    }

    private function reportPaidAfterEnd(Subscription $subscription, mixed $invoiceId): void
    {
        $workspaceId = $subscription->getAttribute($subscription->owner()->getForeignKeyName());

        Log::warning('Managed invoice paid after its subscription ended', [
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscription->stripe_id,
            'invoice_id' => $invoiceId,
        ]);

        report(new UnexpectedValueException(
            "Invoice {$invoiceId} was paid on managed subscription {$subscription->stripe_id} of workspace {$workspaceId} after it ended; nothing was granted.",
        ));
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
        $workspaceId = $metadata['team_id'] ?? null;
        $sessionId = $session['id'] ?? null;
        $customerId = $session['customer'] ?? null;

        if (! is_string($priceId) || ! is_string($workspaceId) || ! is_string($sessionId)) {
            Log::warning('Credit pack checkout ignored: missing or malformed metadata', [
                'session_id' => is_string($sessionId) ? $sessionId : null,
                'missing_fields' => array_keys(array_filter([
                    'metadata.team_id' => ! is_string($workspaceId),
                    'metadata.credit_pack_price' => ! is_string($priceId),
                    'session.id' => ! is_string($sessionId),
                ])),
            ]);

            return $this->successMethod();
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace instanceof Workspace || ! is_string($customerId) || $workspace->stripe_id !== $customerId) {
            Log::warning('Credit pack checkout ignored: workspace/customer mismatch', [
                'workspace_id' => $workspaceId,
                'customer' => $customerId,
                'expected_customer' => $workspace?->stripe_id,
                'session_id' => $sessionId,
            ]);

            return $this->successMethod();
        }

        $this->grantCredits->execute($workspace, $priceId, $sessionId);

        return $this->successMethod();
    }
}
