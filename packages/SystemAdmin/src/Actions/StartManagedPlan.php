<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Mail\ManagedPlanInvoiceMail;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class StartManagedPlan
{
    public function execute(SystemAdministrator $admin, Workspace $workspace, Plan $plan, int $yearlyAmount, ?string $billingEmail = null): Subscription
    {
        abort_unless(Gate::forUser($admin)->allows('manageManagedPlan', $workspace), 403);

        $subscription = Cache::lock("managed-plan-start:{$workspace->getKey()}", 60)
            ->get(fn (): Subscription => $this->start($workspace, $plan, $yearlyAmount, $billingEmail));

        if (! $subscription instanceof Subscription) {
            $this->refuse('Another managed plan is being started for this workspace. Reload the page.');
        }

        return $subscription;
    }

    private function start(Workspace $workspace, Plan $plan, int $yearlyAmount, ?string $billingEmail): Subscription
    {
        $this->assertStartable($workspace, $plan, $yearlyAmount);

        $subscription = $this->createInvoicedSubscription($workspace, $plan, $yearlyAmount);

        $workspace->unsetRelation('subscriptions');

        $invoice = Cashier::stripe()->invoices->sendInvoice($this->latestInvoiceId($subscription));

        if (filled($billingEmail)) {
            Mail::to($billingEmail)->send(new ManagedPlanInvoiceMail(
                $workspace,
                $plan,
                (string) $invoice->hosted_invoice_url,
                $yearlyAmount,
                is_int($invoice->due_date) ? Date::createFromTimestamp($invoice->due_date) : null,
            ));
        }

        return $subscription;
    }

    private function assertStartable(Workspace $workspace, Plan $plan, int $yearlyAmount): void
    {
        if ($yearlyAmount < 1) {
            throw ValidationException::withMessages(['amount' => 'The yearly amount must be at least $1.']);
        }

        if (! $plan->isManaged()) {
            $this->refuse("{$plan->marketingLabel()} is not a managed plan.");
        }

        if ($plan->stripeProduct() === null) {
            $this->refuse("No Stripe product is configured for {$plan->marketingLabel()}.");
        }

        if (blank($workspace->owner?->email)) {
            $this->refuse('The workspace has no owner to send the invoice to.');
        }

        if ($workspace->pendingManagedSubscription() instanceof Subscription) {
            $this->refuse('Another managed invoice is still unpaid. Withdraw it first.');
        }

        if ($workspace->hasStripeId() && (int) $workspace->asStripeCustomer()->balance !== 0) {
            $this->refuse("Clear the customer's Stripe balance first.");
        }
    }

    private function createInvoicedSubscription(Workspace $workspace, Plan $plan, int $yearlyAmount): Subscription
    {
        $this->ensureCustomerHasEmail($workspace);

        return $workspace->newSubscription(SubscriptionType::ManagedPending->value)
            ->ignoreIncompletePayments()
            ->price([
                'price_data' => [
                    'currency' => 'usd',
                    'product' => $plan->stripeProduct(),
                    'unit_amount' => $yearlyAmount * 100,
                    'recurring' => ['interval' => 'year'],
                ],
            ])
            ->create(null, [], [
                'collection_method' => 'send_invoice',
                'days_until_due' => config()->integer('services.stripe.managed_days_until_due'),
                'payment_behavior' => 'allow_incomplete',
                'metadata' => [
                    'workspace_id' => (string) $workspace->getKey(),
                    'type' => SubscriptionType::ManagedPending->value,
                ],
            ]);
    }

    private function ensureCustomerHasEmail(Workspace $workspace): void
    {
        $email = (string) $workspace->owner?->email;

        $customer = $workspace->createOrGetStripeCustomer(['email' => $email, 'name' => $workspace->name]);

        if (blank($customer->email)) {
            $workspace->updateStripeCustomer(['email' => $email]);
        }
    }

    private function latestInvoiceId(Subscription $subscription): string
    {
        return (string) $subscription->asStripeSubscription(['latest_invoice'])->latest_invoice?->id;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['plan' => $message]);
    }
}
