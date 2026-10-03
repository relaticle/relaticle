<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Metrics\Scopes\InternalWorkspace;

final readonly class WorkspaceJourney
{
    /**
     * @return array<string, string>
     */
    public static function facts(Workspace $workspace): array
    {
        return once(fn (): array => self::compute($workspace));
    }

    /**
     * @return array<string, string>
     */
    private static function compute(Workspace $workspace): array
    {
        $owner = $workspace->owner;
        $activity = ActivityDays::from()->where('activity.workspace_id', (string) $workspace->getKey());
        $firstRecord = (clone $activity)->where('activity.kind', 'record')->min('activity.day');
        $activeDays = (clone $activity)->where('activity.day', '>=', now()->subDays(29)->toDateString())->distinct()->count('activity.day');
        $messages = (clone $activity)->where('activity.kind', 'message')->count();
        $creditsUsed = (int) AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_used');

        $mailboxes = ConnectedAccount::query()->whereBelongsTo($workspace)->connected();
        $connected = (clone $mailboxes)->count();
        $needingAttention = (clone $mailboxes)->needingAttention()->count();

        return [
            'Signed up' => $owner instanceof User && $owner->created_at !== null ? $owner->created_at->format('M j, Y') : Money::EMPTY,
            'Signup method' => $owner instanceof User ? SignupMethod::for($owner) : Money::EMPTY,
            'First own record' => $firstRecord === null ? 'None yet' : CarbonImmutable::parse((string) $firstRecord)->format('M j, Y'),
            'Active days (30d)' => "{$activeDays} active ".Str::plural('day', $activeDays),
            'Typed chat messages' => number_format($messages),
            'Credits used this period' => number_format($creditsUsed),
            'Internal' => Workspace::query()->whereKey($workspace->getKey())->withGlobalScope(InternalWorkspace::class, new InternalWorkspace)->exists() ? 'Yes' : 'No',
            'Connected mailboxes' => match (true) {
                $connected === 0 => 'None',
                $needingAttention === 0 => "{$connected} connected",
                default => "{$connected} connected, {$needingAttention} ".($needingAttention === 1 ? 'needs' : 'need').' attention',
            },
        ];
    }
}
