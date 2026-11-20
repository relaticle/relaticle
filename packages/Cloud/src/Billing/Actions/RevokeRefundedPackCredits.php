<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Actions;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

final readonly class RevokeRefundedPackCredits
{
    public function __construct(private CreditService $credits) {}

    public function execute(string $chargeId, string $paymentIntent, ?string $customer, int $paid, int $returned): void
    {
        if ($paid <= 0 || $returned <= 0) {
            return;
        }

        $grant = $this->grantFor($paymentIntent);

        if (! $grant instanceof AiCreditTransaction) {
            return;
        }

        $workspace = Workspace::query()->find($grant->workspace_id);

        if (! $workspace instanceof Workspace || $customer === null || $workspace->stripe_id !== $customer) {
            Log::warning('Credit pack refund ignored: workspace/customer mismatch', [
                'workspace_id' => $grant->workspace_id,
                'customer' => $customer,
                'charge_id' => $chargeId,
            ]);

            return;
        }

        $owed = intdiv($grant->credits_charged * min($returned, $paid), $paid);

        DB::transaction(function () use ($grant, $workspace, $chargeId, $paymentIntent, $owed): void {
            AiCreditTransaction::query()->whereKey($grant->getKey())->lockForUpdate()->first();

            $owedBefore = $this->owedSoFar($workspace, $chargeId);

            if ($owed <= $owedBefore) {
                return;
            }

            $this->credits->revokePurchasedCredits($workspace, $owed - $owedBefore, "pack-revoke-{$chargeId}-{$owed}", [
                'charge_id' => $chargeId,
                'payment_intent' => $paymentIntent,
                'session_id' => $grant->metadata['session_id'] ?? null,
                'owed' => $owed,
            ]);
        });
    }

    private function grantFor(string $paymentIntent): ?AiCreditTransaction
    {
        $grant = AiCreditTransaction::query()
            ->where('type', AiCreditType::Purchase)
            ->where('metadata->payment_intent', $paymentIntent)
            ->first();

        if ($grant instanceof AiCreditTransaction) {
            return $grant;
        }

        $session = Cashier::stripe()->checkout->sessions->all(['payment_intent' => $paymentIntent, 'limit' => 1])->data[0] ?? null;

        return $session === null ? null : AiCreditTransaction::query()
            ->where('type', AiCreditType::Purchase)
            ->where('idempotency_key', GrantPurchasedCredits::IDEMPOTENCY_PREFIX.$session->id)
            ->first();
    }

    private function owedSoFar(Workspace $workspace, string $chargeId): int
    {
        return (int) AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('metadata->charge_id', $chargeId)
            ->get()
            ->max(fn (AiCreditTransaction $revocation): int => (int) ($revocation->metadata['owed'] ?? 0));
    }
}
