<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Contracts\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Tools\Concerns\LocalisesDatetimes;

final readonly class GetCreditBalanceTool implements Tool
{
    use LocalisesDatetimes;

    public function __construct(
        private CreditService $credits,
        private Entitlements $entitlements,
    ) {}

    public function description(): string
    {
        return 'Read the workspace\'s AI credit balance: credits remaining, the allowance each period refills to,'
            .' purchased credits that never expire, and when the period resets. Call this for any question'
            .' about how many AI credits are left, used, or when they renew. Never state a credit figure from memory.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace instanceof Workspace) {
            return (string) json_encode(['error' => 'No workspace is selected.'], JSON_UNESCAPED_SLASHES);
        }

        if (! $this->entitlements->metersCredits($workspace)) {
            return (string) json_encode([
                'metered' => false,
                'note' => 'This installation does not meter AI credits. There is no credit limit.',
            ], JSON_UNESCAPED_SLASHES);
        }

        $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

        return (string) json_encode($this->localiseDatetimes([
            'credits_remaining' => $this->credits->getBalance($workspace),
            'period_allowance' => $this->credits->allowanceFor($workspace),
            'purchased_credits_included' => (int) $balance?->purchased_credits,
            'used_this_period' => (int) $balance?->credits_used,
            'period_resets_at' => $balance?->period_ends_at,
            'note' => 'The message being answered is charged after the reply, so the remaining figure can drop by a few credits.',
        ], $user), JSON_UNESCAPED_SLASHES);
    }
}
