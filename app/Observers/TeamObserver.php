<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Team;
use Illuminate\Support\Str;

final readonly class TeamObserver
{
    public function creating(Team $team): void
    {
        if (blank($team->slug)) {
            $team->slug = self::generateUniqueSlug($team->name);
        }
    }

    private static function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            $baseSlug = Str::lower(Str::random(8));
        }

        $slug = $baseSlug;
        $counter = 2;

        while (Team::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
