<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

/**
 * @extends Factory<EmailBlocklist>
 */
final class EmailBlocklistFactory extends Factory
{
    protected $model = EmailBlocklist::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'type' => EmailBlocklistType::EMAIL,
            'value' => fake()->unique()->safeEmail(),
        ];
    }

    public function email(string $address): static
    {
        return $this->state(fn (): array => [
            'type' => EmailBlocklistType::EMAIL,
            'value' => $address,
        ]);
    }

    public function domain(string $domain): static
    {
        return $this->state(fn (): array => [
            'type' => EmailBlocklistType::DOMAIN,
            'value' => $domain,
        ]);
    }

    public function includeSubdomains(bool $includeSubdomains = true): static
    {
        return $this->state(fn (): array => [
            'include_subdomains' => $includeSubdomains,
        ]);
    }
}
