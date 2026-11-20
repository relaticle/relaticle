<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Billing\Livewire;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Date;
use Livewire\Component;
use Relaticle\Chat\Data\CreditUsage as Usage;
use Relaticle\Chat\Queries\CreditUsageQuery;
use Relaticle\Chat\Services\ModelRegistry;

final class CreditUsage extends Component
{
    private const string STOPPED_REPLY_MODEL = 'incomplete';

    public function render(): View
    {
        $workspace = Filament::getTenant();
        $user = Filament::auth()->user();

        abort_unless($workspace instanceof Workspace && $user instanceof User && $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::BillingManage), 403);

        $timezone = $user->effectiveTimezone();
        $usage = resolve(CreditUsageQuery::class)->forWorkspace($workspace, $timezone);

        return view('cloud::livewire.credit-usage', [
            'since' => $usage->since->setTimezone($timezone)->isoFormat('ll'),
            'total' => $usage->total,
            'days' => $this->days($usage, $timezone),
            'ceiling' => $this->ceiling(max([0, ...array_values($usage->daily)])),
            'members' => count($usage->byMember) > 1 ? $this->members($workspace, $usage) : [],
            'models' => count($usage->byModel) > 1 ? $this->models($usage) : [],
        ]);
    }

    /** @return list<array{label: string, credits: int}> */
    private function days(Usage $usage, string $timezone): array
    {
        return array_map(
            static fn (string $day, int $credits): array => ['label' => Date::parse($day, $timezone)->isoFormat('ll'), 'credits' => $credits],
            array_keys($usage->daily),
            array_values($usage->daily),
        );
    }

    /** @return list<array{name: string, credits: int}> */
    private function members(Workspace $workspace, Usage $usage): array
    {
        $names = $workspace->allUsers()->mapWithKeys(static fn (User $member): array => [(string) $member->getKey() => $member->name]);

        return array_map(
            static fn (string $userId, int $credits): array => ['name' => $names[$userId] ?? __('cloud::billing.usage_tab.former_member'), 'credits' => $credits],
            array_keys($usage->byMember),
            array_values($usage->byMember),
        );
    }

    /** @return list<array{name: string, credits: int}> */
    private function models(Usage $usage): array
    {
        $registry = resolve(ModelRegistry::class);

        return array_map(
            static fn (string $model, int $credits): array => [
                'name' => $model === self::STOPPED_REPLY_MODEL ? __('cloud::billing.usage_tab.stopped') : ($registry->find($model)?->displayLabel() ?? $model),
                'credits' => $credits,
            ],
            array_keys($usage->byModel),
            array_values($usage->byModel),
        );
    }

    // Rounds the axis top up to 1, 2 or 5 times a power of ten, so the gridline labels read as clean numbers.
    private function ceiling(int $peak): int
    {
        if ($peak <= 0) {
            return 1;
        }

        $magnitude = 10 ** (int) floor(log10($peak));

        foreach ([1, 2, 5, 10] as $step) {
            if ($step * $magnitude >= $peak) {
                return $step * $magnitude;
            }
        }

        return 10 * $magnitude;
    }
}
