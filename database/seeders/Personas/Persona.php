<?php

declare(strict_types=1);

namespace Database\Seeders\Personas;

use App\Enums\BillingStatus;
use App\Enums\OnboardingUseCase;

/**
 * One local login and the workspace state it exists to reproduce.
 *
 * Every persona is addressable by slug (`local:seed --only=paused`) and carries
 * the billing status it must render, so the seeder asserts what it produced
 * instead of hoping.
 */
final readonly class Persona
{
    /**
     * @param  string  $purpose  What this login is for, shown in the local persona switcher.
     * @param  array<string, mixed>  $team  Attributes force-filled onto the workspace.
     * @param  ?OnboardingUseCase  $useCase  Drives which fixture set onboarding seeds; null leaves the workspace empty.
     * @param  ?string  $stripe  A Stripe test payment method, when this persona bills for real.
     * @param  array<int, array{email: string, role: string}>  $members
     */
    public function __construct(
        public string $slug,
        public string $email,
        public string $name,
        public string $workspace,
        public string $purpose,
        public BillingStatus $expect,
        public array $team = [],
        public ?OnboardingUseCase $useCase = null,
        public ?string $stripe = null,
        public bool $pastDue = false,
        public array $members = [],
    ) {}

    /**
     * Whether this workspace should come with CRM records. The app seeds those
     * from the onboarding use case, so a persona without one stays empty.
     */
    public function wantsRecords(): bool
    {
        return $this->useCase instanceof OnboardingUseCase;
    }

    /**
     * Whether this persona bills against the Stripe sandbox rather than getting
     * its state force-filled onto the workspace.
     */
    public function needsStripe(): bool
    {
        return $this->stripe !== null;
    }

    public function label(): string
    {
        return "{$this->name} <{$this->email}>";
    }
}
