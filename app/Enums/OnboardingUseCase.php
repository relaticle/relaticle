<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OnboardingUseCase: string implements HasLabel
{
    case Sales = 'sales';
    case CustomerSuccess = 'customer_success';
    case Recruiting = 'recruiting';
    case Marketing = 'marketing';
    case Fundraising = 'fundraising';
    case Investing = 'investing';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::CustomerSuccess => 'Customer Success',
            self::Recruiting => 'Recruiting',
            self::Marketing => 'Marketing',
            self::Fundraising => 'Fundraising',
            self::Investing => 'Investing',
            self::Other => 'Other',
        };
    }

    public function getFixtureSet(): string
    {
        return match ($this) {
            self::Sales, self::CustomerSuccess => 'sales',
            self::Recruiting => 'recruiting',
            self::Marketing => 'marketing',
            self::Fundraising, self::Investing => 'fundraising',
            self::Other => 'general',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Sales => 'Track deals, manage pipeline stages, and close revenue',
            self::CustomerSuccess => 'Manage renewals, health scores, and customer relationships',
            self::Recruiting => 'Manage candidates, interviews, and hiring workflows',
            self::Marketing => 'Track campaigns, leads, and marketing performance',
            self::Fundraising => 'Manage investor outreach, rounds, and due diligence',
            self::Investing => 'Track deal flow, portfolio companies, and investment pipeline',
            self::Other => 'Organize contacts, companies, and tasks your way',
        };
    }

    public function toSubscriberTag(): string
    {
        return "use-case:{$this->value}";
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sales => 'ri-funds-line',
            self::CustomerSuccess => 'ri-hand-heart-line',
            self::Recruiting => 'ri-team-line',
            self::Marketing => 'ri-bar-chart-2-line',
            self::Fundraising => 'ri-money-dollar-circle-line',
            self::Investing => 'ri-stock-line',
            self::Other => 'ri-more-line',
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSubOptions(): array
    {
        return match ($this) {
            self::Sales => [
                'product_led' => 'Product-led',
                'sales_led' => 'Sales-led',
                'inbound' => 'Inbound',
                'outbound' => 'Outbound',
                'smb' => 'SMB',
                'mid_market' => 'Mid-market',
                'enterprise' => 'Enterprise',
            ],
            self::CustomerSuccess => [
                'low_touch' => 'Low-touch',
                'high_touch' => 'High-touch',
                'smb' => 'SMB',
                'mid_market' => 'Mid-market',
                'enterprise' => 'Enterprise',
            ],
            self::Recruiting => [
                'applications' => 'Applications',
                'sourcing' => 'Sourcing',
            ],
            self::Marketing => [
                'content' => 'Content',
                'demand_gen' => 'Demand gen',
                'events' => 'Events',
                'partnerships' => 'Partnerships',
            ],
            self::Fundraising => [
                'early_stage' => 'Early-stage',
                'growth_stage' => 'Growth-stage',
                'late_stage' => 'Late-stage',
            ],
            self::Investing => [
                'early_stage' => 'Early-stage',
                'growth_stage' => 'Growth-stage',
                'late_stage' => 'Late-stage',
            ],
            self::Other => [],
        };
    }
}
