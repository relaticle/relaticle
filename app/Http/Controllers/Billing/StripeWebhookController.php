<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\RestoreWorkspaceTrial;
use App\Models\Team;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

final class StripeWebhookController extends CashierWebhookController
{
    /**
     * Stripe statuses a subscription can be created in without ever granting
     * access — a checkout whose first payment failed or was abandoned.
     *
     * @var list<string>
     */
    private const array NON_GRANTING_STATUSES = ['incomplete', 'incomplete_expired'];

    public function __construct(private readonly RestoreWorkspaceTrial $restoreTrial)
    {
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
}
