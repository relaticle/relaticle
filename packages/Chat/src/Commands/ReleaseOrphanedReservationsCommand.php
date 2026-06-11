<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

/**
 * Refund credit reservations whose turn died between reserve and settle
 * (worker crash, deploy kill, lost job). The refund uses the turn's RESOLUTION
 * key, so if the original job somehow settles later the unique
 * (team_id, idempotency_key) index makes that settle a silent no-op — refund
 * and settle can never both apply.
 */
#[Description('Refund credit reservations that were never settled or refunded')]
#[Signature('chat:release-orphaned-reservations {--age=30 : Minimum reservation age in minutes before it is considered orphaned}')]
final class ReleaseOrphanedReservationsCommand extends Command
{
    public function handle(CreditService $creditService): int
    {
        $age = max(5, (int) $this->option('age'));

        $orphans = AiCreditTransaction::query()
            ->where('type', AiCreditType::Reservation->value)
            ->where('created_at', '<', now()->subMinutes($age))
            ->where('idempotency_key', 'like', 'reserve-%')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ai_credit_transactions as resolved')
                    ->whereColumn('resolved.team_id', 'ai_credit_transactions.team_id')
                    ->whereRaw("resolved.idempotency_key = replace(ai_credit_transactions.idempotency_key, 'reserve-', 'resolve-')");
            })->oldest()
            ->limit(500)
            ->get();

        $refunded = 0;

        foreach ($orphans as $orphan) {
            $this->info("Refunding orphaned reservation `{$orphan->idempotency_key}` for team `{$orphan->team_id}`...");

            $team = Team::query()->find($orphan->team_id);

            if ($team === null) {
                continue;
            }

            $creditService->refundReservation(
                team: $team,
                resolutionKey: str_replace('reserve-', 'resolve-', (string) $orphan->idempotency_key),
                conversationId: $orphan->conversation_id === null ? null : (string) $orphan->conversation_id,
            );

            $refunded++;
        }

        $this->comment("Refunded {$refunded} orphaned reservations.");

        return self::SUCCESS;
    }
}
