<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;

final readonly class EnsureTeamForwardingAddressAction
{
    public function execute(Team $team): TeamForwardingAddress
    {
        $existing = TeamForwardingAddress::query()
            ->where('team_id', $team->getKey())
            ->first();

        if ($existing instanceof TeamForwardingAddress) {
            return $existing;
        }

        $localPart = $this->uniqueLocalPart((string) $team->slug);

        return TeamForwardingAddress::query()->create([
            'team_id' => $team->getKey(),
            'local_part' => $localPart,
        ]);
    }

    private function uniqueLocalPart(string $base): string
    {
        $candidate = Str::lower(Str::slug($base));

        if ($candidate === '') {
            $candidate = 'workspace';
        }

        if (! TeamForwardingAddress::query()->where('local_part', $candidate)->exists()) {
            return $candidate;
        }

        $suffix = 2;

        while (TeamForwardingAddress::query()->where('local_part', "{$candidate}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$candidate}-{$suffix}";
    }
}
