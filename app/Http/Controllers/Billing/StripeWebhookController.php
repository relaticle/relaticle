<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\GrantPurchasedCredits;
use App\Actions\Billing\RestoreWorkspaceTrial;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly RestoreWorkspaceTrial $restoreTrial, private readonly GrantPurchasedCredits $grantCredits)
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

    /**
     * Fulfill credit-pack purchases. Subscription checkouts emit the same event
     * and are ignored here (mode filter) — customer.subscription.* handles them.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        /** @var array<string, mixed> $session */
        $session = $payload['data']['object'] ?? [];

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
