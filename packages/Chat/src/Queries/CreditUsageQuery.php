<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Relaticle\Chat\Data\CreditUsage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class CreditUsageQuery
{
    public function forWorkspace(Workspace $workspace, string $timezone): CreditUsage
    {
        $since = $this->since($workspace);
        $local = "to_char((created_at at time zone 'UTC') at time zone ?, 'YYYY-MM-DD')";

        $spent = $this->spent($workspace, $since)
            ->selectRaw("{$local} as day, sum(credits_charged) as credits", [$timezone])
            ->groupBy('day')
            ->pluck('credits', 'day')
            ->map(static fn (mixed $credits): int => (int) $credits)
            ->all();

        $daily = [];

        foreach (CarbonPeriod::create($since->setTimezone($timezone)->startOfDay(), '1 day', today($timezone)) as $day) {
            $daily[$day->format('Y-m-d')] = $spent[$day->format('Y-m-d')] ?? 0;
        }

        return new CreditUsage(
            since: $since,
            total: array_sum($daily),
            daily: $daily,
            byMember: $this->sumBy($workspace, $since, 'user_id'),
            byModel: $this->sumBy($workspace, $since, 'model'),
        );
    }

    /**
     * @param  'user_id'|'model'  $column
     * @return array<string, int>
     */
    private function sumBy(Workspace $workspace, CarbonImmutable $since, string $column): array
    {
        $bucket = match ($column) {
            'user_id' => "coalesce(user_id::text, '') as bucket, sum(credits_charged) as credits",
            'model' => 'model as bucket, sum(credits_charged) as credits',
        };

        return $this->spent($workspace, $since)
            ->selectRaw($bucket)
            ->groupBy($column)
            ->orderByDesc('credits')
            ->pluck('credits', 'bucket')
            ->map(static fn (mixed $credits): int => (int) $credits)
            ->all();
    }

    /** @return Builder<AiCreditTransaction> */
    private function spent(Workspace $workspace, CarbonImmutable $since): Builder
    {
        return AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->spending()
            ->where('created_at', '>=', $since);
    }

    // credits_used restarts at every grant, so the window opens there and the total matches the balance.
    private function since(Workspace $workspace): CarbonImmutable
    {
        $grantedAt = AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->periodGrants()
            ->max('created_at');

        if (is_string($grantedAt)) {
            return Date::parse($grantedAt);
        }

        $periodStart = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('period_starts_at');

        return $periodStart instanceof CarbonImmutable ? $periodStart : now()->startOfMonth();
    }
}
