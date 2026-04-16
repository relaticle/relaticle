<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

/**
 * @extends Factory<Email>
 */
final class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'connected_account_id' => ConnectedAccount::factory(),
            'rfc_message_id' => '<'.$this->faker->uuid().'@example.com>',
            'provider_message_id' => $this->faker->uuid(),
            'thread_id' => 'thread-'.$this->faker->uuid(),
            'subject' => $this->faker->sentence(),
            'snippet' => $this->faker->text(100),
            'sent_at' => now(),
            'direction' => EmailDirection::INBOUND,
            'folder' => EmailFolder::Inbox,
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => EmailDirection::INBOUND,
            'folder' => EmailFolder::Inbox,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (): array => [
            'direction' => EmailDirection::OUTBOUND,
            'folder' => EmailFolder::Sent,
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (): array => [
            'privacy_tier' => EmailPrivacyTier::PRIVATE,
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (): array => [
            'privacy_tier' => EmailPrivacyTier::FULL,
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn (): array => [
            'is_internal' => true,
        ]);
    }

    public function withAttachments(): static
    {
        return $this->state(fn (): array => [
            'has_attachments' => true,
        ]);
    }
}
