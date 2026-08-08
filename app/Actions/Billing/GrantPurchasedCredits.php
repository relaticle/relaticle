<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Team;
use App\Services\Billing\CreditPackCatalog;
use Illuminate\Support\Facades\Log;
use Relaticle\Chat\Services\CreditService;

final readonly class GrantPurchasedCredits
{
    public function __construct(private CreditService $credits, private CreditPackCatalog $catalog) {}

    /**
     * Fulfill a completed credit-pack checkout. Idempotent on the Stripe
     * checkout session id; unknown prices grant nothing and log.
     */
    public function execute(Team $team, string $priceId, string $sessionId): bool
    {
        $credits = $this->catalog->creditsForPrice($priceId);

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
}
