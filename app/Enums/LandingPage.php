<?php

declare(strict_types=1);

namespace App\Enums;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\Team;
use App\Models\User;

/**
 * The page a user lands on after signing in.
 *
 * Stored as a string on `users.landing_page`. `null` means the default
 * (Dashboard), so existing users keep their current behaviour until they opt in.
 */
enum LandingPage: string
{
    case Dashboard = 'dashboard';
    case People = 'people';
    case Companies = 'companies';
    case Opportunities = 'opportunities';
    case Tasks = 'tasks';
    case Notes = 'notes';

    public function url(Team $team): string
    {
        return match ($this) {
            self::Dashboard => Dashboard::getUrl(['tenant' => $team]),
            self::People => PeopleResource::getUrl('index', ['tenant' => $team]),
            self::Companies => CompanyResource::getUrl('index', ['tenant' => $team]),
            self::Opportunities => OpportunityResource::getUrl('index', ['tenant' => $team]),
            self::Tasks => TaskResource::getUrl('index', ['tenant' => $team]),
            self::Notes => NoteResource::getUrl('index', ['tenant' => $team]),
        };
    }

    public function label(): string
    {
        return __("profile.landing_pages.{$this->value}");
    }

    public static function fromUser(User $user): self
    {
        return $user->landing_page ?? self::Dashboard;
    }
}
