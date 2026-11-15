<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\CustomFields\OpportunityField;
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
            self::Sales => 'sales',
            self::CustomerSuccess => 'customer_success',
            self::Recruiting => 'recruiting',
            self::Marketing => 'marketing',
            self::Fundraising, self::Investing => 'fundraising',
            self::Other => 'general',
        };
    }

    /**
     * Pipeline stages this use case wants, mapped to their colour. Null keeps
     * the opportunity field's own defaults.
     *
     * @return array<string, string>|null
     */
    public function stagePreset(): ?array
    {
        return match ($this) {
            self::CustomerSuccess => [
                'Onboarding' => OptionColor::Purple->value,
                'Active' => OptionColor::Green->value,
                'Renewal due' => OptionColor::Amber->value,
                'At risk' => OptionColor::Orange->value,
                'Renewed' => OptionColor::Cyan->value,
                'Churned' => OptionColor::Gray->value,
            ],
            self::Recruiting => [
                'Sourced' => OptionColor::Purple->value,
                'Applied' => OptionColor::Blue->value,
                'Screen' => OptionColor::Cyan->value,
                'Interview' => OptionColor::Amber->value,
                'Offer' => OptionColor::Magenta->value,
                'Hired' => OptionColor::Green->value,
                'Declined' => OptionColor::Gray->value,
            ],
            self::Fundraising, self::Investing => [
                'Target' => OptionColor::Purple->value,
                'Intro' => OptionColor::Blue->value,
                'First meeting' => OptionColor::Cyan->value,
                'Partner meeting' => OptionColor::Amber->value,
                'Term sheet' => OptionColor::Magenta->value,
                'Closed' => OptionColor::Green->value,
                'Passed' => OptionColor::Gray->value,
            ],
            self::Sales, self::Marketing, self::Other => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function pipelineStages(): array
    {
        return $this->stagePreset() ?? OpportunityField::STAGE->getOptionColors() ?? [];
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
            self::Sales => 'lucide-trending-up',
            self::CustomerSuccess => 'lucide-hand-heart',
            self::Recruiting => 'lucide-users',
            self::Marketing => 'lucide-chart-column',
            self::Fundraising => 'lucide-circle-dollar-sign',
            self::Investing => 'lucide-chart-candlestick',
            self::Other => 'lucide-ellipsis',
        };
    }

    /**
     * @return array<string, string>
     */
    public function getSubOptions(): array
    {
        return match ($this) {
            self::Sales => [
                'outbound' => 'Outbound',
                'inbound' => 'Inbound',
                'product_led' => 'Product-led',
                'partner_led' => 'Partner-led',
            ],
            self::CustomerSuccess => [
                'high_touch' => 'High-touch',
                'low_touch' => 'Low-touch',
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
            self::Fundraising, self::Investing => [
                'early_stage' => 'Early-stage',
                'growth_stage' => 'Growth-stage',
                'late_stage' => 'Late-stage',
            ],
            self::Other => [],
        };
    }
}
