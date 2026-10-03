<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics\Scopes;

use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Support\CatalogEntry;
use Relaticle\SystemAdmin\Metrics\ActivityDays;

final readonly class TrialFarmer implements Scope
{
    /**
     * @param  Builder<Workspace>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder
            ->whereNotNull($model->qualifyColumn('pro_trial_used_at'))
            ->whereNotIn($model->qualifyColumn('id'), ActivityDays::workspacesWithOwnData())
            ->where(fn (Builder $either): Builder => $either
                ->whereIn($model->qualifyColumn('id'), $this->mostlyPremiumWorkspaceIds())
                ->orWhereIn($model->qualifyColumn('user_id'), User::query()->whereIn('timezone', $this->timezones())->select('id')));
    }

    private function mostlyPremiumWorkspaceIds(): QueryBuilder
    {
        $premium = $this->premiumModels();

        if ($premium === []) {
            return DB::query()->selectRaw('null::text as workspace_id')->whereRaw('false');
        }

        $placeholders = implode(', ', array_fill(0, count($premium), '?'));

        return AiCreditTransaction::query()
            ->where('type', AiCreditType::Chat)
            ->groupBy('workspace_id')
            ->havingRaw("2 * coalesce(sum(credits_charged) filter (where model in ({$placeholders})), 0) >= sum(credits_charged)", $premium)
            ->havingRaw('sum(credits_charged) > 0')
            ->toBase()
            ->select('workspace_id');
    }

    /**
     * @return list<string>
     */
    private function premiumModels(): array
    {
        /** @var array<int, mixed> $catalog */
        $catalog = (array) config('chat.models', []);

        return collect($catalog)
            ->map(fn (mixed $entry): ?CatalogEntry => is_array($entry) ? CatalogEntry::fromArray($entry) : null)
            ->filter(fn (?CatalogEntry $entry): bool => $entry instanceof CatalogEntry && $entry->minPlan !== Plan::Free)
            ->map(fn (CatalogEntry $entry): string => $entry->model)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function timezones(): array
    {
        return array_values(array_filter((array) config('system-admin.abuse_timezones', []), is_string(...)));
    }
}
