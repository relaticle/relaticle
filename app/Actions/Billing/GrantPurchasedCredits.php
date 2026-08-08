<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Relaticle\Chat\Services\CreditService;

final readonly class GrantPurchasedCredits
{
    public function __construct(private CreditService $credits) {}

    /**
     * Fulfill a completed credit-pack checkout. Idempotent on the Stripe
     * checkout session id; unknown prices grant nothing and log.
     */
    public function execute(Team $team, string $priceId, string $sessionId): bool
    {
        $credits = $this->packCredits($priceId);

        if ($credits === null) {
            Log::warning('Credit pack checkout ignored: unknown price', [
                'team_id' => $team->getKey(),
                'price_id' => $priceId,
                'session_id' => $sessionId,
            ]);

            return false;
        }

        return $this->credits->addPurchasedCredits($team, $credits, "pack-{$sessionId}", [
            'price_id' => $priceId,
            'session_id' => $sessionId,
        ]);
    }

    private function packCredits(string $priceId): ?int
    {
        /** @var array<string, array{price: string|null, credits: int}> $packs */
        $packs = config('services.stripe.credit_packs', []);

        foreach ($packs as $pack) {
            if (($pack['price'] ?? null) === $priceId) {
                return $pack['credits'];
            }
        }

        return null;
    }
}
