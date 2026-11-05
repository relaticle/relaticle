<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Actions;

use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\Cloud\Analytics\Models\Acquisition;

final readonly class RecordSubscriptionOutcome
{
    /** @param array<string, mixed> $payload */
    public function execute(array $payload): void
    {
        $invoice = $payload['data']['object'] ?? [];
        $paid = ($payload['type'] ?? null) === 'invoice.paid';
        $timestamp = $paid ? $this->paidTimestamp($invoice) : $this->failureTimestamp($payload);
        $invoiceId = $invoice['id'] ?? null;
        $customer = $invoice['customer'] ?? null;
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;

        if ($timestamp === null || ! is_string($invoiceId) || ! is_string($customer) || ! is_string($subscriptionId)
            || ($invoice['parent']['type'] ?? null) !== 'subscription_details') {
            return;
        }

        $workspace = Workspace::query()->where('stripe_id', $customer)->first();

        if (! $workspace instanceof Workspace || ! Acquisition::query()->where('workspace_id', $workspace->getKey())->exists()) {
            return;
        }

        if (! $this->subscriptionBelongsTo($workspace, $subscriptionId)) {
            return;
        }

        if ($paid && ! $this->paidThroughStripe($invoice)) {
            return;
        }

        if (! $paid) {
            $this->verifyPriorPayment($workspace, $subscriptionId, $timestamp);
        }

        $this->record($workspace, $paid, $invoiceId, Date::createFromTimestampUTC($timestamp));
    }

    /** @param array<string, mixed> $invoice */
    private function paidTimestamp(array $invoice): ?int
    {
        $timestamp = $invoice['status_transitions']['paid_at'] ?? null;

        if (($invoice['status'] ?? null) !== 'paid' || ! is_int($invoice['amount_paid'] ?? null) || $invoice['amount_paid'] <= 0
            || ! in_array($invoice['billing_reason'] ?? null, ['subscription_create', 'subscription_cycle', 'subscription_update', 'subscription_threshold'], true)) {
            return null;
        }

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
    }

    /** @param array<string, mixed> $payload */
    private function failureTimestamp(array $payload): ?int
    {
        $invoice = $payload['data']['object'] ?? [];
        $timestamp = $payload['created'] ?? null;

        if (($payload['type'] ?? null) !== 'invoice.payment_failed' || ($invoice['billing_reason'] ?? null) !== 'subscription_cycle'
            || ($invoice['attempt_count'] ?? null) !== 1) {
            return null;
        }

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
    }

    private function subscriptionBelongsTo(Workspace $workspace, string $subscriptionId): bool
    {
        $subscription = Subscription::query()->where('stripe_id', $subscriptionId)->first();

        if ($subscription instanceof Subscription) {
            return $subscription->owner()->first()?->is($workspace) === true
                && SubscriptionType::tryFrom($subscription->type) !== null;
        }

        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($subscriptionId);
        $workspaceId = $stripeSubscription->metadata->workspace_id ?? null;

        return $stripeSubscription->id === $subscriptionId
            && $stripeSubscription->customer === $workspace->stripe_id
            && SubscriptionType::tryFrom($stripeSubscription->metadata->type ?? $stripeSubscription->metadata->name ?? 'default') !== null
            && ($workspaceId === null || $workspaceId === (string) $workspace->getKey());
    }

    /** @param array<string, mixed> $invoice */
    private function paidThroughStripe(array $invoice): bool
    {
        if (($invoice['paid_out_of_band'] ?? false) === true) {
            return false;
        }

        if (! array_key_exists('amount_paid_off_stripe', $invoice)) {
            $invoice = Cashier::stripe()->invoices->retrieve($invoice['id'], [
                'expand' => ['amount_paid_off_stripe'],
            ])->toArray();
        }

        return ($invoice['amount_paid_off_stripe'] ?? null) === 0;
    }

    private function verifyPriorPayment(Workspace $workspace, string $subscriptionId, int $failedAt): void
    {
        $acquisition = Acquisition::query()->where('workspace_id', $workspace->getKey())->first();

        if (! $acquisition instanceof Acquisition || ($acquisition->first_paid_at !== null && $acquisition->first_paid_at->getTimestamp() <= $failedAt)) {
            return;
        }

        $invoices = Cashier::stripe()->invoices->all([
            'customer' => $workspace->stripe_id,
            'subscription' => $subscriptionId,
            'status' => 'paid',
            'limit' => 100,
            'expand' => ['data.amount_paid_off_stripe'],
        ]);
        $earliest = null;

        foreach ($invoices->autoPagingIterator() as $invoice) {
            $data = $invoice->toArray();
            $paidAt = $this->paidTimestamp($data);

            if ($paidAt !== null && $paidAt >= $acquisition->captured_at->getTimestamp() && $paidAt <= $failedAt && $invoice->customer === $workspace->stripe_id
                && ($data['parent']['subscription_details']['subscription'] ?? null) === $subscriptionId
                && $this->paidThroughStripe($data)
                && ($earliest === null || [$paidAt, $invoice->id] < [$earliest['at'], $earliest['id']])) {
                $earliest = ['at' => $paidAt, 'id' => $invoice->id];
            }
        }

        if ($earliest !== null) {
            $this->record($workspace, true, $earliest['id'], Date::createFromTimestampUTC($earliest['at']));
        }
    }

    private function record(Workspace $workspace, bool $paid, string $invoiceId, CarbonImmutable $occurredAt): void
    {
        $column = $paid ? 'first_paid' : 'first_renewal_failed';

        Acquisition::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('captured_at', '<=', $occurredAt)
            ->unless($paid, fn (Builder $query): Builder => $query->where('first_paid_at', '<=', $occurredAt))
            ->where(function (Builder $query) use ($column, $occurredAt, $invoiceId): void {
                $query->whereNull("{$column}_at")
                    ->orWhere("{$column}_at", '>', $occurredAt)
                    ->orWhere(function (Builder $query) use ($column, $occurredAt, $invoiceId): void {
                        $query->where("{$column}_at", $occurredAt)->where("{$column}_invoice_id", '>', $invoiceId);
                    });
            })
            ->update(["{$column}_at" => $occurredAt, "{$column}_invoice_id" => $invoiceId]);
    }
}
