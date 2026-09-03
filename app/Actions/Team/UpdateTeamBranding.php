<?php

declare(strict_types=1);

namespace App\Actions\Team;

use App\Enums\TeamAccent;
use App\Models\Team;

final readonly class UpdateTeamBranding
{
    public function execute(Team $team, ?string $logoPath, ?TeamAccent $accent): void
    {
        $team->update([
            'logo_path' => $logoPath,
            'accent_color' => $accent?->value,
        ]);
    }
}
