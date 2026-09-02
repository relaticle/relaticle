<?php

declare(strict_types=1);

namespace App\Enums;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\TaskResource;
use App\Models\User;
use App\Models\Workspace;

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

    public function url(Workspace $workspace): string
    {
        return match ($this) {
            self::Dashboard => Dashboard::getUrl(['tenant' => $workspace]),
            self::People => PeopleResource::getUrl('index', ['tenant' => $workspace]),
            self::Companies => CompanyResource::getUrl('index', ['tenant' => $workspace]),
            self::Opportunities => OpportunityResource::getUrl('index', ['tenant' => $workspace]),
            self::Tasks => TaskResource::getUrl('index', ['tenant' => $workspace]),
            self::Notes => NoteResource::getUrl('index', ['tenant' => $workspace]),
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
