<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;

/**
 * @extends Factory<UserForwardingSettings>
 */
final class UserForwardingSettingsFactory extends Factory
{
    protected $model = UserForwardingSettings::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'team_id' => Team::factory(),
            'sharing_tier' => EmailPrivacyTier::METADATA_ONLY,
        ];
    }
}
